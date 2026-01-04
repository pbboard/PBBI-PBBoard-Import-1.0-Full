<?php
/**
 * PBBI - PBBoard Import System
 *
 * @author    PBBoard Team
 * @license   GPL-3.0-or-later
 * @link      https://github.com/pbboard/PBBI-PBBoard-Import-1.0-Full
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation.
 */
(!defined('IN_PowerBB')) ? die() : '';

class XenForo2_Importer {
    private $db_source;
    private $db_pbb;
    private $pbb_prefix;
    private $group_styles = [];
    private $post_with_attachments = [];
	private $xf_url = '';
	private $pbb_url = '';

    public function __construct() {

        // 1. الاتصال بقاعدة بيانات PBBoard (الهدف)
        $config_file = dirname(__DIR__, 2) . '/includes/config.php';
        if (file_exists($config_file)) {
            require($config_file);
            $this->pbb_prefix = $config['db']['prefix'];
            $this->db_pbb = @new mysqli($config['db']['server'], $config['db']['username'], $config['db']['password'], $config['db']['name']);
            $this->db_pbb->set_charset("utf8mb4");
        }

        // 2. الاتصال بقاعدة بيانات XenForo 2 (المصدر)
        $this->db_source = @new mysqli($_SESSION['import_host'], $_SESSION['import_username'], $_SESSION['import_password'], $_SESSION['import_dbname'], $_SESSION['import_port']);
$forced_charset = isset($_SESSION['import_charset']) ? trim($_SESSION['import_charset']) : '';
if (!empty($forced_charset)) {
    $this->db_source->set_charset($forced_charset);
} else {
    $this->db_source->set_charset("utf8mb4"); // الترميز الافتراضي الآمن لهذا السكريبت
}
        // 3. كاش تنسيقات المجموعات (من PBBoard)
        if ($this->db_pbb) {
            $res = $this->db_pbb->query("SELECT id, username_style FROM {$this->pbb_prefix}group");
            if ($res) {
                while ($g = $res->fetch_assoc()) {
                    $this->group_styles[$g['id']] = $g['username_style'];
                }
            }
        }

        // 4. كاش المرفقات (من XenForo 2)
        if ($this->db_source) {
            $src_prefix = $_SESSION['import_tablePrefix'];
            $res_attach = $this->db_source->query("SELECT DISTINCT content_id FROM {$src_prefix}attachment WHERE content_type = 'post'");
            if ($res_attach) {
                while ($row_a = $res_attach->fetch_assoc()) {
                    $this->post_with_attachments[$row_a['content_id']] = true;
                }
            }
        }

        $this->loadExchangeUrls();
    }

    public function processStep($step, $offset, $limit) {
        $offset = (int)$offset;

        if ($step == 'forums' && $offset == 0) {
            $this->importGeneralSettings();
        }

        if ($offset == 0) {
            $this->truncateTargetTable($step);
        }

        $records = $this->fetchFromSource($step, $offset, $limit);
        $total_in_source = $this->countSourceRecords($step);

        foreach ($records as $row) {
            $this->insertIntoPBB($step, $row);
        }

        $processed = $offset + count($records);
        $isComplete = ($processed >= $total_in_source) || (count($records) < $limit);

        return [
            'status' => $isComplete ? 'step_complete' : 'continue',
            'percent' => ($total_in_source > 0) ? min(100, round(($processed / $total_in_source) * 100)) : 100,
            'total_processed' => $processed
        ];
    }

    private function importGeneralSettings() {
        $prefix = $_SESSION['import_tablePrefix'];
        // في XF2 الإعدادات مخزنة في جدول xf_option
        $res = $this->db_source->query("SELECT option_id, option_value FROM {$prefix}option WHERE option_id IN ('boardTitle', 'contactEmailAddress', 'boardActive')");
        if ($res) {
            while ($s = $res->fetch_assoc()) {
                if ($s['option_id'] == 'boardTitle') {
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='".$this->escape($s['option_value'])."' WHERE var_name='title'");
                } elseif ($s['option_id'] == 'contactEmailAddress') {
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='".$this->escape($s['option_value'])."' WHERE var_name='site_mail'");
                } elseif ($s['option_id'] == 'boardActive') {
                    $status = ($s['option_value'] == 1) ? 0 : 1; // PBBoard يستخدم 1 للإغلاق، XF يستخدم 0
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='{$status}' WHERE var_name='board_close'");
                }
            }
        }
    }

      private function loadExchangeUrls() {
        if (!$this->db_source) return;

        $prefix = $_SESSION['import_tablePrefix'];

        // جلب رابط PBBoard من ملف الإعدادات
        $settings_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'settings.php';
        if (file_exists($settings_path)) {
            require($settings_path);
            $this->pbb_url = rtrim($setting['forum_url'], '/') . '/';
        }

        // جلب رابط XenForo من قاعدة البيانات
        $res = $this->db_source->query("SELECT option_value FROM {$prefix}option WHERE option_id = 'boardUrl'");
        if ($res && $row = $res->fetch_assoc()) {
            $this->xf_url = rtrim($row['option_value'], '/') . '/';
        }
    }
private function truncateTargetTable($step) {
    $map = [
        'users' => 'member',
        'forums' => 'section',
        'threads' => 'subject',
        'posts' => 'reply',
        'privateMessages' => 'pm',
        'attachments' => 'attach',
        'moderators' => 'moderators',
        'polls' => 'poll',
        'votes' => 'vote'
    ];

    if (isset($map[$step])) {
        $table = $this->pbb_prefix . $map[$step];

        if ($step == 'users') {
            $current_admin = $_SESSION['PowerBB_admin_username'];

            // حذف الجميع باستثناء الآدمن الحالي لحماية الجلسة
            $this->db_pbb->query("DELETE FROM $table WHERE username != '" . $this->escape($current_admin) . "'");

            // إعادة ضبط العداد التلقائي
            $this->db_pbb->query("ALTER TABLE $table AUTO_INCREMENT = 1");
        } else {
            $this->db_pbb->query("TRUNCATE TABLE $table");
        }
    }
}

    private function insertIntoPBB($step, $row) {
        switch ($step) {

			case 'forums':
		    $parent_id = (int)$row['parent_node_id'];
		    if ($row['node_type_id'] == 'Category' && $parent_id < 2) {
		        $parent_id = 0;
		    }

		    $threads = isset($row['thread_count']) ? $row['thread_count'] : 0;
		    $replies = isset($row['message_count']) ? ($row['message_count'] - $threads) : 0;

		    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}section SET
		        id                   = '{$row['node_id']}',
		        title                = '".$this->escape($row['title'])."',
		        section_describe     = '".$this->escape($row['description'])."',
		        parent               = '{$parent_id}',
		        sort                 = '{$row['node_id']}',
		        subject_num          = '{$threads}',
		        reply_num            = '{$replies}',
		        last_writer          = '".$this->escape($row['last_post_username'])."',
		        last_subject         = '".$this->escape($row['last_thread_title'])."',
		        last_subjectid       = '{$row['last_thread_id']}',
		        last_date            = '{$row['last_post_date']}',
		        last_time            = '{$row['last_post_date']}',
		        last_reply           = '{$row['last_post_id']}',
		        use_power_code_allow = '1',
		        show_sig             = '1',
		        icon                 = 'look/images/icons/i1.gif',
		        sectionpicture_type  = '2',
		        subject_order        = '1',
		        linksection          = '0',
		        use_section_picture  = '0',
		        sec_section          = '0',
		        review_subject       = '0',
		        trash                = '0'");
		    break;

case 'users':
    $group_map = [
        3 => 1, // Administrative -> المدير العام
        4 => 3, // Moderating     -> مشرف
        2 => 4, // Registered     -> عضو مسجل
        1 => 5, // Unregistered   -> زائر
        5 => 6  // Banned         -> مطرود
    ];

    $primary_group = (int)$row['user_group_id'];
    $secondary_groups = !empty($row['secondary_group_ids']) ? explode(',', $row['secondary_group_ids']) : [];

    // تحديد المجموعة النهائية
    if ($primary_group == 3 || in_array('3', $secondary_groups)) {
        $final_pbb_group = 1;
    } elseif ($primary_group == 4 || in_array('4', $secondary_groups)) {
        $final_pbb_group = 3;
    } elseif (isset($group_map[$primary_group])) {
        $final_pbb_group = $group_map[$primary_group];
    } else {
        $final_pbb_group = 4;
    }

    // تجهيز الاسم وفحص التشابه
    $clean_username = $this->cleanMessage($row['username']);
    $current_admin_username = $_SESSION['PowerBB_admin_username'];
    $is_me = false;

    if ($clean_username == $current_admin_username) {
        // التحقق من البريد للتأكد أنه الآدمن الذي يقوم بالتحويل
        if ($row['email'] == $_SESSION['PowerBB_admin_email']) {
            $is_me = true;
        } else {
            // اسم مشابه لشخص آخر، يتم تمييزه
            $clean_username .= "1";
        }
    }

    $style = isset($this->group_styles[$final_pbb_group]) ? $this->group_styles[$final_pbb_group] : '[username]';
    $username_style_cache = str_replace('[username]', $clean_username, $style);

    // --- معالجة الأفاتار ---
    $new_avatar_path = "";
    if (isset($row['avatar_date']) && $row['avatar_date'] > 0) {
        $user_id = (int)$row['user_id'];
        $sub_folder = floor($user_id / 1000);
        $xf_path = rtrim($_SESSION['import_uploads_path'], '/\\');

        $sizes = ['l', 'm', 's'];
        foreach ($sizes as $size) {
            $source_avatar = $xf_path . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $size . DIRECTORY_SEPARATOR . $sub_folder . DIRECTORY_SEPARATOR . $user_id . '.jpg';

            if (file_exists($source_avatar)) {
                $avatar_filename = $user_id . '_' . $row['avatar_date'] . '.jpg';
                $target_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'download' . DIRECTORY_SEPARATOR . 'avatar' . DIRECTORY_SEPARATOR;
                if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);

                if (@copy($source_avatar, $target_dir . $avatar_filename)) {
                    $new_avatar_path = 'download/avatar/' . $avatar_filename;
                    break;
                }
            }
        }
    }

    // تجهيز البيانات
    $esc_username = $this->escape($clean_username);
    $esc_email    = $this->escape($row['email']);
    $esc_sig      = $this->escape($this->cleanMessage($row['signature']));
    $esc_avatar   = $this->escape($new_avatar_path);
    $esc_style_ch = $this->escape($username_style_cache);

    $extra_fields = "
        username_style_cache  = '{$esc_style_ch}',
        usergroup             = '{$final_pbb_group}',
        posts                 = '{$row['message_count']}',
        lastvisit             = '{$row['last_activity']}',
        register_date         = '{$row['register_date']}',
        avater_path           = '{$esc_avatar}',
        user_sig              = '{$esc_sig}',
        visitor               = '1',
        style                 = '1',
        style_id_cache        = '1',
        send_allow            = '1',
        hide_online           = '0',
        unread_pm             = '0',
        keepmeon              = '0',
        warnings              = '0',
        reputation            = '10',
        pm_window             = '1',
        profile_viewers       = '1',
        user_gender           = 'm',
        lang                  = '1'";

    if ($is_me) {
        // تحديث بيانات الآدمن في مكانه للحفاظ على الجلسة
        $session_pass = $this->escape($_SESSION['PowerBB_admin_password']);
        $session_salt = $this->escape($_SESSION['active_number']);

        $this->db_pbb->query("UPDATE {$this->pbb_prefix}member SET
            email         = '{$esc_email}',
            password      = '{$session_pass}',
            active_number = '{$session_salt}',
            $extra_fields
            WHERE username = '".$this->escape($current_admin_username)."'");

        $_SESSION['PowerBB_admin_username'] = $clean_username;
    } else {
        // إدخال الأعضاء الآخرين
        $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}member SET
            id            = '{$row['user_id']}',
            username      = '{$esc_username}',
            password      = '".$this->escape(md5(uniqid()))."',
            active_number = '".$this->escape($this->generate_salt())."',
            email         = '{$esc_email}',
            $extra_fields");
    }
    break;

			case 'threads':
			    $visible = ($row['message_state'] == 'visible') ? 0 : 1;
			    $attach_subject = ($row['attach_count'] > 0) ? 1 : 0;
			    $message = $this->cleanMessage($row['message']);
			    $closed = ($row['discussion_open'] == 1) ? 0 : 1;

			    // تصحيح: 0 تعني لا يوجد استفتاء، 1 يوجد (حسب نظام PBB القياسي)
			    $poll = 0;

			    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}subject SET
			        id                  = '{$row['thread_id']}',
			        title               = '".$this->escape($row['title'])."',
			        text                = '".$this->escape($message)."',
			        section             = '{$row['node_id']}',
			        writer              = '".$this->escape($row['username'])."',
			        native_write_time   = '{$row['post_date']}',
			        poll_subject        = '{$poll}',
			        stick               = '{$row['sticky']}',
			        close               = '{$closed}',
			        review_subject      = '{$visible}',
			        visitor             = '{$row['view_count']}',
			        icon                = 'look/images/icons/i1.gif',
			        reply_number        = '{$row['reply_count']}',
			        last_replier        = '".$this->escape($row['last_post_username'])."',
			        attach_subject      = '{$attach_subject}',
			        write_time          = '{$row['post_date']}'");
			    break;


			case 'posts':
			    $visible = ($row['message_state'] == 'visible') ? 0 : 1;
			    $attach_reply = ($row['attach_count'] > 0) ? 1 : 0;
			    $message = $this->cleanMessage($row['message']);

			    // إذا كان هناك تعديل للمشاركة في XF
			    $action_by = ($row['last_edit_date'] > 0) ? $this->escape($row['username']) : '';
			    $edit_time = ($row['last_edit_date'] > 0) ? $row['last_edit_date'] : 0;

			    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}reply SET
			        id              = '{$row['post_id']}',
			        subject_id      = '{$row['thread_id']}',
					section         = '{$row['node_id']}',
			        title           = 'RE: ".$this->escape($row['thread_title'])."',
			        text            = '".$this->escape($message)."',
			        writer          = '".$this->escape($row['username'])."',
			        attach_reply    = '{$attach_reply}',
			        review_reply    = '{$visible}',
			        icon            = 'look/images/icons/i1.gif',
			        actiondate      = '{$edit_time}',
			        reason_edit     = '',
			        action_by       = '{$action_by}',
			        write_time      = '{$row['post_date']}'");
			    break;

				case 'attachments':
				    // 1. حساب المسارات ونسخ الملف الفيزيائي
				    $xf_path = rtrim($_SESSION['import_uploads_path'], '/\\');
				    $sub_folder = floor($row['data_id'] / 1000);
				    $source_file = $xf_path . DIRECTORY_SEPARATOR . 'internal_data' . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . $sub_folder . DIRECTORY_SEPARATOR . "{$row['data_id']}-{$row['file_hash']}.data";
				    $new_filename = time() . '_' . $row['filename'];
				    $target_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'download' . DIRECTORY_SEPARATOR;

				    if (file_exists($source_file)) {
				        @copy($source_file, $target_dir . $new_filename);
				    }

				    // 2. منطق الربط حسب تعليماتك الدقيقة:
				    if ((int)$row['content_id'] === (int)$row['first_post_id']) {
				        // حالة المرفق في "موضوع"
				        $subject_id = $row['thread_id']; // رقم الموضوع
				        $reply      = 0;                // القيمة 0 للمواضيع
				    } else {
				        // حالة المرفق في "رد"
				        $subject_id = $row['content_id']; // رقم الرد (المشاركة)
				        $reply      = 1;                 // القيمة 1 للردود
				    }

				    // 3. إدخال البيانات في جدول المرفقات
				    if ($subject_id > 0) {
				        $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}attach SET
				            filename      = '".$this->escape($row['filename'])."',
				            filepath      = 'download/".$this->escape($new_filename)."',
				            filesize      = '{$row['file_size']}',
				            subject_id    = '{$subject_id}',
				            reply         = '{$reply}',
				            u_id          = '{$row['user_id']}',
				            visitor       = '{$row['view_count']}',
				            time          = '{$row['upload_date']}',
				            extension     = '".$this->escape(pathinfo($row['filename'], PATHINFO_EXTENSION))."'");
				    }
				    break;

				case 'polls':
				    // تحويل النص المجمع إلى JSON كما يطلبه PBBoard
				    $options_array = explode('||~|~||', $row['options_text']);
				    $json_answers = json_encode($options_array, JSON_UNESCAPED_UNICODE);

				    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}poll SET
				        id         = '{$row['poll_id']}',
				        qus        = '".$this->escape($row['question'])."',
				        answers    = '".$this->escape($json_answers)."',
				        subject_id = '{$row['thread_id']}'");

				    // تفعيل الاستفتاء في الموضوع ليظهر للأعضاء
				    $this->db_pbb->query("UPDATE {$this->pbb_prefix}subject SET poll_subject = '1' WHERE id = '{$row['thread_id']}'");
				    break;

				case 'votes':
				    $username = !empty($row['username']) ? $row['username'] : 'Guest';

				    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}vote SET
				        poll_id       = '{$row['poll_id']}',
				        member_id     = '{$row['user_id']}',
				        answer_number = '{$row['option_index']}',
				        votes         = '1',
				        subject_id    = '{$row['thread_id']}',
				        user_ip       = '127.0.0.1',
				        username      = '".$this->escape($username)."'");
				    break;

			case 'privateMessages':
			    $row['message'] = $this->cleanMessage($row['message']);
			    $subject = $this->escape($row['subject']);
			    $message = $this->escape($row['message']);
			    $from    = $this->escape($row['from_name']);
			    $to      = !empty($row['to_name']) ? $this->escape($row['to_name']) : 'Guest';
			    $date    = $row['dateline'];

			    // تحويل حالة القراءة: إذا كان unread=0 (XF) يعني مقروء=1 (PBB)
			    $read_status = ($row['is_unread'] == 0) ? 1 : 0;

			    // 1. نسخة المجلد الوارد (Inbox) للمستقبل
			    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}pm SET
			        title     = '$subject',
			        text      = '$message',
			        date      = '$date',
			        icon      = 'look/images/icons/i1.gif',
			        folder    = 'inbox',
			        user_read = '$read_status',
			        user_from = '$from',
			        user_to   = '$to'");

			    // 2. نسخة المجلد الصادر (Sent) للمرسل
			    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}pm SET
			        title     = '$subject',
			        text      = '$message',
			        date      = '$date',
			        icon      = 'look/images/icons/i1.gif',
			        folder    = 'sent',
			        user_read = '1', -- المرسل قرأها بالتأكيد
			        user_from = '$from',
			        user_to   = '$to'");
			    break;

			case 'moderators':
			    // التأكد من وجود اسم مستخدم، وإلا نضع معرفه كاسم مؤقت
			    $mod_username = !empty($row['username']) ? $row['username'] : 'User_ID_' . $row['uid'];
			    $mod_uid      = $row['uid'];

			    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}moderators SET
			        id         = '{$row['mid']}',
			        section_id = '{$row['fid']}',
			        member_id  = '".$mod_uid."',
			        username   = '".$this->escape($mod_username)."'");
			    break;
        }
    }

    private function fetchFromSource($step, $offset, $limit) {
        $prefix = $_SESSION['import_tablePrefix'];
        switch ($step) {
		case 'forums':
		    return $this->db_source->query("
		        SELECT n.node_id, n.title, n.description, n.display_order, n.parent_node_id, n.node_type_id,
		               f.discussion_count AS thread_count,
		               f.message_count,
		               f.last_post_id,
		               f.last_post_date,
		               f.last_post_username,
		               f.last_thread_id,
		               f.last_thread_title
		        FROM {$prefix}node n
		        LEFT JOIN {$prefix}forum f ON n.node_id = f.node_id
		        WHERE n.node_type_id IN ('Forum', 'Category')
		        LIMIT $offset, $limit
		    ")->fetch_all(MYSQLI_ASSOC);

			case 'users':
			    return $this->db_source->query("
			        SELECT u.*, p.signature
			        FROM {$prefix}user u
			        LEFT JOIN {$prefix}user_profile p ON p.user_id = u.user_id
			        ORDER BY u.user_id ASC
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);

			case 'threads':
			    return $this->db_source->query("
			        SELECT t.thread_id, t.node_id, t.title, t.user_id, t.username,
			               t.post_date, t.view_count, t.reply_count, t.sticky, t.discussion_open,
			               t.last_post_username,
			               0 AS poll_id, -- وضعنا صفر لتجنب خطأ العمود غير الموجود
			               p.message, p.message_state, p.attach_count
			        FROM {$prefix}thread t
			        INNER JOIN {$prefix}post p ON p.post_id = t.first_post_id
			        ORDER BY t.thread_id ASC
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);

			case 'posts':
			    return $this->db_source->query("
			        SELECT p.*, t.title as thread_title
			        FROM {$prefix}post p
			        INNER JOIN {$prefix}thread t ON t.thread_id = p.thread_id
			        WHERE p.post_id NOT IN (SELECT first_post_id FROM {$prefix}thread)
			        ORDER BY p.post_id ASC
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);

			case 'attachments':
			    return $this->db_source->query("
			        SELECT a.attachment_id, a.content_id, a.view_count,
			               ad.data_id, ad.file_hash, ad.filename, ad.file_size, ad.user_id, ad.upload_date,
			               p.thread_id, t.first_post_id
			        FROM {$prefix}attachment a
			        INNER JOIN {$prefix}attachment_data ad ON a.data_id = ad.data_id
			        INNER JOIN {$prefix}post p ON p.post_id = a.content_id
			        INNER JOIN {$prefix}thread t ON t.thread_id = p.thread_id
			        WHERE a.content_type = 'post'
			        ORDER BY a.attachment_id ASC
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);

			case 'polls':
			    // تصحيح: اسم الحقل هو response
			    return $this->db_source->query("
			        SELECT p.poll_id, p.question, p.content_id as thread_id,
			               GROUP_CONCAT(pr.response ORDER BY pr.poll_response_id SEPARATOR '||~|~||') as options_text
			        FROM {$prefix}poll p
			        INNER JOIN {$prefix}poll_response pr ON p.poll_id = pr.poll_id
			        WHERE p.content_type = 'thread'
			        GROUP BY p.poll_id
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);

			case 'votes':
			    // ربط الأصوات بجدول poll_vote وجلب ترتيب الخيار
			    return $this->db_source->query("
			        SELECT v.poll_id, v.user_id, v.poll_response_id,
			               u.username, p.content_id as thread_id,
			               (SELECT COUNT(*) FROM {$prefix}poll_response pr
			                WHERE pr.poll_id = v.poll_id AND pr.poll_response_id < v.poll_response_id) as option_index
			        FROM {$prefix}poll_vote v
			        LEFT JOIN {$prefix}user u ON u.user_id = v.user_id
			        INNER JOIN {$prefix}poll p ON p.poll_id = v.poll_id
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);

			case 'privateMessages':
			    return $this->db_source->query("
			        SELECT
			            m.message_id,
			            m.message_date as dateline,
			            m.message as message,
			            cm.title as subject,
			            u_from.username as from_name,
			            -- جلب اسم المستقبل (أول مشارك غير المرسل)
			            (SELECT u.username FROM {$prefix}conversation_recipient cr
			             LEFT JOIN {$prefix}user u ON u.user_id = cr.user_id
			             WHERE cr.conversation_id = m.conversation_id AND cr.user_id != m.user_id LIMIT 1) as to_name,
			            -- جلب حالة القراءة للمستقبل
			            (SELECT cu.is_unread FROM {$prefix}conversation_user cu
			             WHERE cu.conversation_id = m.conversation_id AND cu.owner_user_id != m.user_id LIMIT 1) as is_unread
			        FROM {$prefix}conversation_message m
			        INNER JOIN {$prefix}conversation_master cm ON cm.conversation_id = m.conversation_id
			        LEFT JOIN {$prefix}user u_from ON u_from.user_id = m.user_id
			        ORDER BY m.message_id ASC
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);

			case 'moderators':
			    return $this->db_source->query("
			        SELECT
			            mc.moderator_id as mid,
			            mc.content_id as fid,
			            mc.user_id as uid,
			            u.username
			        FROM {$prefix}moderator_content mc
			        LEFT JOIN {$prefix}user u ON u.user_id = mc.user_id
			        WHERE mc.content_type = 'node'
			        ORDER BY mc.moderator_id ASC
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);
        }
    }

    public function countSourceRecords($step) {
        $prefix = $_SESSION['import_tablePrefix'];
        $map = [
            'forums' => "{$prefix}node WHERE node_type_id = 'Forum'",
            'users' => "{$prefix}user",
            'threads' => "{$prefix}thread",
            'posts' => "{$prefix}post",
            'attachments' => "{$prefix}attachment WHERE content_type = 'post'"
        ];
        if (!isset($map[$step])) return 0;
        $res = $this->db_source->query("SELECT COUNT(*) FROM " . $map[$step]);
        return (int)$res->fetch_row()[0];
    }

    private function escape($str) {
        return $this->db_pbb ? $this->db_pbb->real_escape_string($str) : addslashes($str);
    }

    private function _Get_Extension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

	private function cleanMessage($content) {
	    if (empty($content)) return '';

	    $content = stripslashes($content);

	    // 1. تصغير الأوسمة (توحيد النمط)
	    $content = preg_replace_callback('/\[(\/?)([a-z0-9]+)([^\]]*)\]/i', function($matches) {
	        return "[" . $matches[1] . strtolower($matches[2]) . $matches[3] . "]";
	    }, $content);

	    // 2. استبدال الرابط القديم بالرابط الجديد (الروابط المطلقة)
	    if (!empty($this->xf_url) && !empty($this->pbb_url)) {
	        $content = str_ireplace($this->xf_url, $this->pbb_url, $content);
	    }

	    // 3. تصحيح الروابط الداخلية (Threads, Forums, Members)
	    $content = preg_replace('/index\.php\?threads\/(\d+)\/?/i', 'index.php?page=topic&show=1&id=$1', $content);
	    $content = preg_replace('/index\.php\?forums\/(\d+)\/?/i', 'index.php?page=forum&show=1&id=$1', $content);
	    $content = preg_replace('/index\.php\?members\/(\d+)\/?/i', 'index.php?page=profile&show=1&id=$1', $content);
	    $content = preg_replace('/index\.php\?attachments\/(\d+)\/?/i', 'index.php?page=download&attach=1&id=$1', $content);

	    // 4. معالجة unfurl و heading والاقتباسات
	    $content = preg_replace('/\[url\s+unfurl="true"\](.*?)\[\/url\]/i', '[url=$1]$1[/url]', $content);
	    $content = preg_replace('/\[heading=(\d+)\](.*?)\[\/heading\]/i', '[h$1]$2[/h$1]', $content);
	    $content = preg_replace('/\[quote\s*=\s*"?([^,\]"]+)(?:,[^\]]+)?"?\]/i', '[quote=$1]', $content);

	    // 5. تنظيف الوسوم المكررة والغير مدعومة
	    $content = preg_replace('/(\[b\])+/i', '[b]', $content);
	    $content = preg_replace('/(\[\/b\])+/i', '[/b]', $content);

	    $unsupported_tags = ['ispoiler', 'spoiler', 'icode'];
	    foreach ($unsupported_tags as $tag) {
	        $content = preg_replace("/\[$tag\](.*?)\[\/$tag\]/i", '$1', $content);
	    }

	    return trim($content);
	}

	private function generate_salt() {
		$charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#%!_';
		$randStringLen = 8;

		$randString = "";
		for ($i = 0; $i < $randStringLen; $i++) {
		$randString .= $charset[mt_rand(0, strlen($charset) - 1)];
		}

		return $randString;
	}
}