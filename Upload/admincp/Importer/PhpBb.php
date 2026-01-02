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

class PhpBb_Importer {
    private $db_source;
    private $db_pbb;
    private $pbb_prefix;
    private $group_styles = [];

    public function __construct() {
        // 1. الاتصال بقاعدة بيانات PBBoard
        $config_file = dirname(__DIR__, 2) . '/includes/config.php';
        if (file_exists($config_file)) {
            require($config_file);
            $this->pbb_prefix = $config['db']['prefix'];
            $this->db_pbb = @new mysqli($config['db']['server'], $config['db']['username'], $config['db']['password'], $config['db']['name']);
            $this->db_pbb->set_charset("utf8mb4");
        }

        // 2. الاتصال بقاعدة بيانات PhpBB (المصدر)
        $this->db_source = @new mysqli($_SESSION['import_host'], $_SESSION['import_username'], $_SESSION['import_password'], $_SESSION['import_dbname'], $_SESSION['import_port']);
        if ($this->db_source) {
            $this->db_source->set_charset("utf8mb4");
        }

        // 3. كاش تنسيقات المجموعات
        if ($this->db_pbb) {
            $res = $this->db_pbb->query("SELECT id, username_style FROM {$this->pbb_prefix}group");
            if ($res) {
                while ($g = $res->fetch_assoc()) {
                    $this->group_styles[$g['id']] = $g['username_style'];
                }
            }
        }
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

    private function get_phpbb_config($config_name) {
        $prefix = $_SESSION['import_tablePrefix'];
        $res = $this->db_source->query("SELECT config_value FROM {$prefix}config WHERE config_name = '$config_name'");
        if ($res) {
            $row = $res->fetch_assoc();
            return $row['config_value'] ?? '';
        }
        return '';
    }

    private function importGeneralSettings() {
        $sitename  = $this->get_phpbb_config('sitename');
        $site_desc = $this->get_phpbb_config('site_desc');
        $email     = $this->get_phpbb_config('board_email');

        if (!empty($sitename)) {
            $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='".$this->escape($sitename)."' WHERE var_name='title'");
            $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='".$this->escape($site_desc)."' WHERE var_name='description'");
        }

        if (!empty($email)) {
            $esc_email = $this->escape($email);
            $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='{$esc_email}' WHERE var_name='site_mail'");
            $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='{$esc_email}' WHERE var_name='admin_email'");
            $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='{$esc_email}' WHERE var_name='send_email'");
        }
        $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='localhost' WHERE var_name='smtp_server'");
    }

private function truncateTargetTable($step) {
    // خريطة كاملة وشاملة لجميع العمليات وجداول PBBoard المقابلة لها
    $map = [
        'users'           => 'member',
        'forums'          => 'section',
        'threads'         => 'subject',
        'posts'           => 'reply',
        'moderators'      => 'moderators',
        'privateMessages' => 'pm',
        'attachments'     => 'attach',
        'polls'           => 'poll',
        'votes'           => 'vote'
    ];

    if (isset($map[$step])) {
        $table = $this->pbb_prefix . $map[$step];

        if ($step == 'users') {
            // منطق حماية الآدمن الحالي لضمان استمرار الجلسة
            $current_admin = $_SESSION['PowerBB_admin_username'];

            // حذف الجميع باستثناء الآدمن الذي يقوم بالتحويل حالياً
            $this->db_pbb->query("DELETE FROM $table WHERE username != '" . $this->escape($current_admin) . "'");

            // إعادة ضبط العداد التلقائي (سيقوم MySQL بالبدء من أكبر ID موجود + 1)
            $this->db_pbb->query("ALTER TABLE $table AUTO_INCREMENT = 1");
        } else {
            // إفراغ بقية الجداول تماماً وتصفير العدادات التلقائية
            $this->db_pbb->query("TRUNCATE TABLE $table");
        }
    }
}

    private function insertIntoPBB($step, $row) {
        switch ($step) {
 case 'users':
    // 1. استثناء العناكب، البوتات، وعضوية الزائر (Anonymous)
    if ($row['user_type'] == 2 || $row['user_id'] == 1) {
        break;
    }

    // تعيين المجموعات (تأكد من مطابقة الأرقام لمنتداك)
    $group_map = [5 => 1, 4 => 8, 2 => 4, 3 => 5, 7 => 4];
    $group = isset($group_map[$row['group_id']]) ? $group_map[$row['group_id']] : 4;
    $style = isset($this->group_styles[$group]) ? $this->group_styles[$group] : '[username]';

    $clean_username = $this->smartClean($row['username']);
    $current_admin_username = $_SESSION['PowerBB_admin_username']; // الاسم الموجود في PBBoard حالياً

    // --- منطق فحص الآدمن وتشابه الأسماء ---
    $is_me = false;

    if ($clean_username == $current_admin_username) {
        // التحقق عن طريق البريد الإلكتروني للتأكد أن هذا المستخدم هو الآدمن نفسه
        if ($row['email'] == $_SESSION['PowerBB_admin_email']) {
            $is_me = true;
        } else {
            // إذا كان اسم المستخدم في vB يشبه اسم الآدمن في PBB ولكنهما شخصان مختلفان
            $clean_username .= "1";
        }
    }

    $username_style_cache = str_replace('[username]', $clean_username, $style);

    // --- بداية معالجة الأفاتار ---
    $phpbb_root = rtrim($_SESSION['import_uploads_path'], '/\\');
    $target_dir = dirname(__DIR__, 2) . "/download/avatar/";
    if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);

    $new_avatar_path = "";
    if (!empty($row['user_avatar'])) {

        // 1. الأفاتار المرفوع (تعديل منطق اسم الملف)
        if ($row['user_avatar_type'] == 1 || $row['user_avatar_type'] == 'avatar.driver.upload') {
            // في phpBB الاسم الفعلي للملف على السيرفر يكون بصيغة: {group_id}_{user_id}.ext
            // أو أحياناً يتم تخزين القيمة المشفرة في العمود. سنحاول الاحتمالين:

            $avatar_name = $row['user_avatar'];
            // إذا كان الاسم يبدأ بـ "g" أو مجرد أرقام، phpBB قد يغير التسمية
            $source_file = $phpbb_root . "/images/avatars/upload/" . $avatar_name;

            // محاولة ثانية: أحياناً يكون الاسم المخزن هو "salt_userid.ext"
            if (!file_exists($source_file)) {
                // محاولة البحث عن الملف ببادئة ID العضو (نمط شائع في phpBB)
                $files = glob($phpbb_root . "/images/avatars/upload/*_" . $row['user_id'] . ".*");
                if ($files) $source_file = $files[0];
            }

            if (file_exists($source_file)) {
                $extension = strtolower(pathinfo($source_file, PATHINFO_EXTENSION));
                $target_filename = "avatar_" . $row['user_id'] . "_" . time() . "." . $extension;
                if (@copy($source_file, $target_dir . $target_filename)) {
                    $new_avatar_path = 'download/avatar/' . $target_filename;
                }
            }
        }
        // 2. الأفاتار الخارجي (رابط مباشر)
        elseif ($row['user_avatar_type'] == 2 || $row['user_avatar_type'] == 'avatar.driver.remote') {
            $new_avatar_path = $row['user_avatar'];
        }
        // 3. أفاتار المعرض
        elseif ($row['user_avatar_type'] == 3 || $row['user_avatar_type'] == 'avatar.driver.gallery') {
            $source_file = $phpbb_root . "/images/avatars/gallery/" . $row['user_avatar'];
            $gallery_filename = basename($row['user_avatar']);
            if (file_exists($source_file) && @copy($source_file, $target_dir . $gallery_filename)) {
                $new_avatar_path = 'download/avatar/' . $gallery_filename;
            }
        }
    }
    // --- نهاية معالجة الأفاتار ---

    // 4. معالجة التوقيع (استخدام smartClean لتنظيف نظام XML الخاص بـ phpBB)
    $clean_sig = $this->smartClean($row['user_sig']);

    // تجهيز البيانات للإدخال
    $esc_username = $this->escape($clean_username);
    $esc_pass     = $this->escape($row['user_password']);
    $esc_salt     = $this->escape($row['user_form_salt']);
    $esc_email    = $this->escape($row['user_email']);
    $esc_sig      = $this->escape($clean_sig);
    $esc_style_ch = $this->escape($username_style_cache);
    $esc_avatar   = $this->escape($new_avatar_path);

    $extra_fields = "
        reputation = '".(int)$row['user_rank']."',
        warnings = '".(int)$row['user_reminded']."',
        visitormessage = '1',
        pm_window = '1',
        user_gender = 'm',
        unread_pm = '".(int)$row['user_new_privmsg']."',
        send_allow = '1',
        style_id_cache = '1',
        should_update_style_cache = '0',
        usergroup = '{$group}',
        posts = '".(int)$row['user_posts']."',
        lastvisit = '".(int)$row['user_lastvisit']."',
        register_date = '".(int)$row['user_regdate']."',
        user_sig = '{$esc_sig}',
        username_style_cache = '{$esc_style_ch}',
        avater_path = '{$esc_avatar}',
        visitor = '1', style = '1', lang = '1'";

    // 5. التنفيذ في قاعدة البيانات
    if ($is_me) {
        $this->db_pbb->query("UPDATE {$this->pbb_prefix}member SET
            email = '{$esc_email}',
            password = '{$esc_pass}',
            active_number = '{$esc_salt}',
            $extra_fields
            WHERE username = '{$this->escape($current_admin_username)}'");
    } else {
        $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}member SET
            id = '{$row['user_id']}',
            username = '{$esc_username}',
            password = '{$esc_pass}',
            active_number = '{$esc_salt}',
            email = '{$esc_email}',
            $extra_fields");
    }
    break;

case 'forums':
    // في phpBB، الأقسام الرئيسية (Categories) يكون لها parent_id = 0
    $parentid = (int)$row['parent_id'];

    $title = $this->smartClean(strip_tags($row['forum_name']));
    $description = $this->smartClean($row['forum_desc']);
    $lastposter = $this->smartClean($row['forum_last_poster_name']);
    $lastthread = $this->smartClean($row['forum_last_post_subject']);

    // التحقق مما إذا كان القسم عبارة عن رابط خارجي
    $linksection = ($row['forum_type'] == 2) ? '1' : '0';
    $forum_link = isset($row['forum_get_str']) ? $row['forum_get_str'] : '';

    $forumid    = (int)$row['forum_id'];
    $replycount = (int)$row['forum_posts'];
    $threadcount= (int)$row['forum_topics'];
    $sort_order = (int)$row['left_id']; // استخدام left_id للترتيب المنطقي

    if ($row['forum_type'] == 0 || $parentid == 0) {
        // القسم الرئيسي (الفئة - Category)
        $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}section SET
            id                   = '{$forumid}',
            title                = '".$this->escape($title)."',
            section_describe     = '',
            last_date            = '',
            last_writer          = '',
            last_reply           = '',
            last_time            = '',
            last_subject         = '',
            last_subjectid       = '',
            linksite             = '',
            section_password     = '',
            linksection          = '0',
            sort                 = '{$sort_order}',
            subject_num          = '0',
            reply_num            = '0',
            show_sig             = '0',
            subject_order        = '0',
            use_power_code_allow = '0',
            review_subject       = '0',
            icon                 = '',
            last_berpage_nm      = '0',
            sectionpicture_type  = '0',
            parent               = '0'");
    } else {
        // المنتديات الفرعية (Forums)
        $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}section SET
            id                   = '{$forumid}',
            title                = '".$this->escape($title)."',
            section_describe     = '".$this->escape($description)."',
            last_date            = '{$row['forum_last_post_time']}',
            last_writer          = '".$this->escape($lastposter)."',
            last_reply           = '{$row['forum_last_post_id']}',
            last_time            = '{$row['forum_last_post_time']}',
            last_subject         = '".$this->escape($lastthread)."',
            last_subjectid       = '{$row['forum_last_topic_id']}',
            linksite             = '".$this->escape($forum_link)."',
            section_password     = '',
            linksection          = '{$linksection}',
            sort                 = '{$sort_order}',
            subject_num          = '{$threadcount}',
            reply_num            = '{$replycount}',
            subject_order        = '1',
            show_sig             = '1',
            use_power_code_allow = '1',
            review_subject       = '0',
            sectionpicture_type  = '2',
            icon                 = 'look/images/icons/i1.gif',
            last_berpage_nm      = '{$replycount}',
            parent               = '{$parentid}'");
    }
    break;

case 'moderators':
    $mod_username = !empty($row['mod_name']) ? $row['mod_name'] : 'User_ID_' . $row['uid'];
    $mod_uid      = (int)$row['uid'];
    $section_id   = (int)$row['fid'];

    // ملاحظة: PBBoard يستخدم auto-increment للحقل id في جدول المشرفين عادةً،
    // لذا يفضل عدم إرسال id يدوي لضمان عدم التكرار إلا إذا كنت تريد مطابقة كاملة.
    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}moderators SET
        section_id = '{$section_id}',
        member_id  = '{$mod_uid}',
        username   = '".$this->escape($mod_username)."'");
    break;

case 'threads':
    // في phpBB: 1 تعني مغلق، 0 مفتوح
    $closed = ($row['topic_status'] == 1) ? 1 : 0;

    // في phpBB: 0 عادي، 1 مثبت، 2 إعلان، 3 إعلان عام
    $sticky = ($row['topic_type'] > 0) ? 1 : 0;

    // تنظيف الرسالة من أكواد phpBB الخاصة (BBCode UID)
    $message = $this->smartClean($row['post_text']);

    // عدد الردود = إجمالي المشاركات - 1 (لأن phpBB يحسب الموضوع معهم)
    $replies = max(0, (int)$row['topic_posts_approved'] - 1);

    $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}subject SET
        id                = '{$row['topic_id']}',
        title             = '".$this->escape($row['topic_title'])."',
        text              = '".$this->escape($message)."',
        section           = '{$row['forum_id']}',
        writer            = '".$this->escape($row['topic_first_poster_name'])."',
        native_write_time = '{$row['topic_time']}',
        poll_subject      = '".(!empty($row['poll_title']) ? 1 : 0)."',
        stick             = '{$sticky}',
        close             = '{$closed}',
        review_subject    = '0',
        visitor           = '{$row['topic_views']}',
        icon              = 'look/images/icons/i1.gif',
        reply_number      = '{$replies}',
        last_replier      = '".$this->escape($row['topic_last_poster_name'])."',
        attach_subject    = '".($row['topic_attachment'] > 0 ? 1 : 0)."',
        write_time        = '{$row['topic_time']}'");
    break;

case 'posts':
    // في phpBB (النسخ الحديثة): 1 هو المرئي، 0 هو المنتظر للمراجعة
    // في PBBoard: 0 هو المرئي، 1 هو المنتظر للمراجعة
    // سنقوم بعكس القيمة لكي تتوافق مع نظام PBBoard
    $visible_status = (isset($row['visible']) && $row['visible'] == 1) ? 0 : 1;

    $attach_reply = ($row['has_attach'] > 0) ? 1 : 0;
    $message = $this->smartClean($row['message']);

    $action_by = ($row['edituid'] > 0) ? $this->escape($row['editor_name']) : '';
    $writer = !empty($row['author_name']) ? $row['author_name'] : 'Guest';

    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}reply SET
        id            = '{$row['pid']}',
        subject_id    = '{$row['tid']}',
        title         = '".$this->escape($row['subject'])."',
        text          = '".$this->escape($message)."',
        writer        = '".$this->escape($writer)."',
        attach_reply  = '{$attach_reply}',
        review_reply  = '{$visible_status}', -- هنا نضع الحالة الصحيحة
        section       = '{$row['fid']}',
        icon          = 'look/images/icons/i1.gif',
        actiondate    = '{$row['edittime']}',
        reason_edit   = '".$this->escape($row['editreason'])."',
        action_by     = '".$action_by."',
        write_time    = '{$row['dateline']}'");
    break;

case 'privateMessages':
    // 1. تحديد المجلد (Sent أو Inbox)
    // إذا كان الشخص الذي استلم السجل هو نفسه الكاتب، تظهر في الصادر، وإلا في الوارد
    $folder = ($row['author_id'] == $row['recipient_id']) ? "sent" : "inbox";

    // 2. تنظيف محتوى الرسالة (XML + BBCode)
    $message = $this->smartClean($row['message']);

    // 3. حالة القراءة (في phpBB: pm_unread = 0 تعني مقروءة)
    $is_read = ($row['pm_unread'] == 0) ? 1 : 0;

    // 4. إدخال البيانات في جدول PBBoard
    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}pm SET
        title     = '".$this->escape($row['subject'])."',
        text      = '".$this->escape($message)."',
        date      = '{$row['dateline']}',
        icon      = 'look/images/icons/i1.gif',
        folder    = '{$folder}',
        user_read = '{$is_read}',
        user_from = '".$this->escape($row['from_name'])."',
        user_to   = '".$this->escape($row['to_name'])."'");
    break;

case 'attachments':
    // 1. تجهيز مسميات الحقول من الكود القديم
    $attachmentid = $row['attach_id'];
    $filename     = $row['real_filename'];
    $physical_filename = $row['physical_filename'];
    $threadid     = $row['topic_id'];
    $postid       = $row['post_msg_id']; // رقم المشاركة في phpBB
    $extension    = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // 2. تحديد المسارات (المسار الرئيسي + files)
    $source_dir = rtrim($_SESSION['import_uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR;
    $source_file = $source_dir . $physical_filename;

    // اسم الملف عند النقل (دمج الاسم الفيزيائي مع الأصلي لضمان التفرد كما في الكود القديم)
    $target_filename = $physical_filename . $filename;
    $target_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'download' . DIRECTORY_SEPARATOR . $target_filename;

    // 3. عملية النسخ
    $file_copied = false;
    if (file_exists($source_file)) {
        if (@copy($source_file, $target_path)) {
            $file_copied = true;
        }
    }

    // 4. تطبيق المنطق الذي جلبته:
    // إذا كان رقم الموضوع يساوي رقم المشاركة، فهذا يعني أنه في رأس الموضوع (اجعل الرد 0)
    // وإلا، اترك رقم المشاركة كما هو ليخزن في حقل reply
    if ((int)$threadid === (int)$postid) {
        $reply_value = 0;
    } else {
        $reply_value = $postid;
    }

    // 5. الإدخال في قاعدة البيانات (حتى لو لم ينسخ الملف للتأكد من امتلاء الجدول)
    $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}attach SET
        id          = '" . $attachmentid . "',
        filename    = '" . $this->escape($filename) . "',
        filepath    = 'download/" . $this->escape($target_filename) . "',
        filesize    = '" . $row['filesize'] . "',
        subject_id  = '" . $threadid . "',
        visitor     = '" . $row['download_count'] . "',
        u_id        = '" . $row['poster_id'] . "',
        reply       = '" . $reply_value . "',
        time        = '" . $row['filetime'] . "',
        extension   = '" . $this->escape($extension) . "'");
    break;

case 'polls':
    $tid = $row['tid'];
    $prefix = $_SESSION['import_tablePrefix'];

    // 1. جلب خيارات الاستطلاع من phpBB
    $options_query = $this->db_source->query("
        SELECT poll_option_text
        FROM {$prefix}poll_options
        WHERE topic_id = '{$tid}'
        ORDER BY poll_option_id ASC
    ");

    $options_array = [];
    while ($opt = $options_query->fetch_assoc()) {
        // تمرير كل خيار على دالة smartClean لتنظيف أوسمة <t> و <s>
        $options_array[] = $this->smartClean($opt['poll_option_text']);
    }

    // 2. تنظيف السؤال أيضاً وتحويل المصفوفة إلى JSON
    $clean_question = $this->smartClean($row['question']);
    $json_answers = json_encode($options_array, JSON_UNESCAPED_UNICODE);

    // 3. الإدخال في جدول poll
    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}poll SET
        qus        = '".$this->escape($clean_question)."',
        answers    = '".$this->escape($json_answers)."',
        subject_id = '{$tid}'");

    // 4. تحديث حقل poll_subject في جدول المواضيع لظهور الاستطلاع
    $this->db_pbb->query("UPDATE {$this->pbb_prefix}subject SET poll_subject = '1' WHERE id = '{$tid}'");
    break;


case 'votes':
    // 1. معالجة اسم المستخدم: إذا كان العضو زائراً أو غير موجود
    $username = !empty($row['username']) ? $row['username'] : 'Guest';

    // 2. معالجة رقم الخيار:
    // phpBB يخزن الخيارات بدءاً من 1، بينما PBBoard يتوقعها كمصفوفة تبدأ من 0
    $answer_number = (int)$row['voteoption'] - 1;
    if ($answer_number < 0) $answer_number = 0;

    // 3. الإدخال في جدول vote (حسب خريطة truncateTargetTable لديك)
    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}vote SET
        poll_id       = '{$row['pid']}',
        member_id     = '{$row['uid']}',
        answer_number = '{$answer_number}',
        votes         = '1',
        subject_id    = '{$row['tid']}',
        user_ip       = '".$this->escape($row['ipaddress'])."',
        username      = '".$this->escape($username)."'");
    break;

        }
    }

    private function fetchFromSource($step, $offset, $limit) {
        $prefix = $_SESSION['import_tablePrefix'];
        switch ($step) {
            case 'users':
                $sql = "SELECT user_id, username, user_password, user_form_salt, user_email, group_id, user_posts, user_regdate, user_lastvisit, user_rank, user_reminded, user_new_privmsg, user_sig, user_sig_bbcode_uid, user_avatar, user_avatar_type
                        FROM {$prefix}users WHERE group_id NOT IN (1, 6) ORDER BY user_id ASC LIMIT $offset, $limit";
                return $this->db_source->query($sql)->fetch_all(MYSQLI_ASSOC);
case 'forums':
    return $this->db_source->query("SELECT * FROM {$prefix}forums ORDER BY forum_id ASC LIMIT $offset, $limit")->fetch_all(MYSQLI_ASSOC);
case 'moderators':
    return $this->db_source->query("
        SELECT
            m.forum_id as fid,
            m.user_id as uid,
            u.username as mod_name
        FROM {$prefix}moderator_cache m
        INNER JOIN {$prefix}users u ON u.user_id = m.user_id
        WHERE m.user_id > 0
        GROUP BY m.forum_id, m.user_id
        LIMIT $offset, $limit
    ")->fetch_all(MYSQLI_ASSOC);

case 'threads':
    return $this->db_source->query("
        SELECT
            t.topic_id,
            t.forum_id,
            t.topic_title,
            t.topic_poster,
            t.topic_first_poster_name,
            t.topic_time,
            t.topic_views,
            t.topic_posts_approved,
            t.topic_status,
            t.topic_type,
            t.topic_last_poster_name,
            t.topic_attachment,
            p.post_text,
            p.post_id,
            t.poll_title
        FROM {$prefix}topics t
        INNER JOIN {$prefix}posts p ON p.post_id = t.topic_first_post_id
        WHERE t.topic_visibility = 1
        ORDER BY t.topic_id ASC
        LIMIT $offset, $limit
    ")->fetch_all(MYSQLI_ASSOC);

case 'posts':
    return $this->db_source->query("
        SELECT
            p.post_id as pid, p.topic_id as tid, p.forum_id as fid,
            p.post_subject as subject, p.post_text as message,
            p.post_time as dateline,
            p.post_visibility as visible, -- هذا هو الحقل الذي ظهر في الـ SQL الخاص بك
            p.post_attachment as has_attach, p.post_edit_time as edittime,
            p.post_edit_reason as editreason, p.post_edit_user as edituid,
            u.username as author_name,
            u_edit.username as editor_name
        FROM {$prefix}posts p
        INNER JOIN {$prefix}topics t ON p.topic_id = t.topic_id
        LEFT JOIN {$prefix}users u ON p.poster_id = u.user_id
        LEFT JOIN {$prefix}users u_edit ON p.post_edit_user = u_edit.user_id
        WHERE p.post_id != t.topic_first_post_id
        ORDER BY p.post_id ASC
        LIMIT $offset, $limit
    ")->fetch_all(MYSQLI_ASSOC);

case 'privateMessages':
    return $this->db_source->query("
        SELECT
            pm.msg_id,
            pm.message_subject as subject,
            pm.message_text as message,
            pm.message_time as dateline,
            pm.author_id,
            pm_to.user_id as recipient_id,
            pm_to.pm_unread,
            pm_to.pm_deleted,
            u_from.username AS from_name,
            u_to.username AS to_name
        FROM {$prefix}privmsgs pm
        INNER JOIN {$prefix}privmsgs_to pm_to ON pm.msg_id = pm_to.msg_id
        LEFT JOIN {$prefix}users u_from ON u_from.user_id = pm.author_id
        LEFT JOIN {$prefix}users u_to ON u_to.user_id = pm_to.user_id
        WHERE pm_to.pm_deleted = 0
        ORDER BY pm.msg_id ASC
        LIMIT $offset, $limit
    ")->fetch_all(MYSQLI_ASSOC);

case 'attachments':
    return $this->db_source->query("
        SELECT
            attach_id, real_filename, filesize, post_msg_id,
            download_count, poster_id, topic_id, filetime,
            physical_filename, mimetype, in_message
        FROM {$prefix}attachments
        ORDER BY attach_id ASC
        LIMIT $offset, $limit
    ")->fetch_all(MYSQLI_ASSOC);

case 'polls':
    // جلب المواضيع التي تحتوي على استطلاعات
    return $this->db_source->query("
        SELECT
            topic_id as pid,
            topic_id as tid,
            poll_title as question,
            poll_start as dateline
        FROM {$prefix}topics
        WHERE poll_title != '' AND poll_start > 0
        ORDER BY topic_id ASC
        LIMIT $offset, $limit
    ")->fetch_all(MYSQLI_ASSOC);

case 'votes':
    // جلب الأصوات مع ربطها بجداول الأعضاء والمواضيع
    return $this->db_source->query("
        SELECT
            v.topic_id as pid,
            v.vote_user_id as uid,
            v.poll_option_id as voteoption,
            v.vote_user_ip as ipaddress,
            u.username,
            v.topic_id as tid
        FROM {$prefix}poll_votes v
        LEFT JOIN {$prefix}users u ON v.vote_user_id = u.user_id
        ORDER BY v.topic_id ASC
        LIMIT $offset, $limit
    ")->fetch_all(MYSQLI_ASSOC);

                default:
                return [];
        }
    }

public function countSourceRecords($step) {
    $prefix = $_SESSION['import_tablePrefix'];
    $map = [
        'forums' => 'forums',
        'users' => 'users',
        'threads' => 'topics',
        'posts' => 'posts',
        'polls' => 'topics WHERE poll_title != ""', // نحسب المواضيع التي بها استطلاع فقط
        'votes' => 'poll_votes'
    ];
    if (!isset($map[$step])) return 0;

    // التعامل مع شرط WHERE في حال وجوده في الخريطة
    $table_info = $map[$step];
    $res = $this->db_source->query("SELECT COUNT(*) FROM {$prefix}{$table_info}");
    return $res ? (int)$res->fetch_row()[0] : 0;
}

    private function escape($str) {
        return $this->db_pbb->real_escape_string($str);
    }

private function smartClean($string) {
    if (empty($string)) return '';

    // 1. تنظيف الهروب الزائد
    $string = stripslashes($string);

    // 2. معالجة نظام phpBB XML (إصدار 3.2 فما فوق)
    // نبحث عن محتوى أوسمة <s> و <e> ونبقي عليه، ثم نحذف بقية وسوم XML/HTML
    // هذا سيحول <SIZE size="50"><s>[size=50]</s>... إلى [size=50]
    $string = preg_replace('/<(s|e|i)>/i', '', $string);
    $string = preg_replace('/<\/(s|e|i)>/i', '', $string);

    // إزالة أوسمة XML المعقدة مثل <QUOTE author="..."> أو <URL url="...">
    // مع الإبقاء على النص الموجود داخل أوسمة <s> و <e> التي نظفناها في السطر السابق
    $string = preg_replace('/<[A-Z0-9_]+[^>]*>/i', '', $string);
    $string = preg_replace('/<\/[A-Z0-9_]+>/i', '', $string);

    // 3. إزالة نظام bbcode_uid القديم (لضمان التوافق مع النسخ الأقدم)
    $string = preg_replace('/\:u:[a-z0-9]+|:[a-z0-9]+/i', '', $string);

    // 4. إصلاح الرموز المرجعية للـ HTML (مثل &quot; و &lt;)
    $string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');

    // 5. توحيد حالة أحرف أوسمة الروابط
    $string = str_ireplace('[url', '[URL', $string);
    $string = str_ireplace('[/url]', '[/URL]', $string);

    // 6. تصحيح الروابط الداخلية
    $search = [
        'viewtopic.php?t=', 'viewforum.php?f=',
        'memberlist.php?mode=viewprofile&u=', 'download/file.php?id=',
        'showthread.php?tid=', 'forumdisplay.php?fid='
    ];
    $replace = [
        'index.php?page=topic&show=1&id=', 'index.php?page=forum&show=1&id=',
        'index.php?page=profile&show=1&id=', 'index.php?page=download&attach=1&id=',
        'index.php?page=topic&show=1&id=', 'index.php?page=forum&show=1&id='
    ];
    $string = str_replace($search, $replace, $string);


    // 7. تصغير أحرف أوسمة BBCode فقط
    $string = preg_replace_callback('/\[(\/?)([a-z0-9]+)([^\]]*)\]/i', function($matches) {
        return "[" . $matches[1] . strtolower($matches[2]) . $matches[3] . "]";
    }, $string);

    // 8. تحويل وسم الاقتباس (تعديل لدعم الصيغة المطلوبة)
$string = preg_replace('/\[quote=([^ ]+)\s+post_id=(\d+)\s+time=(\d+)\s+user_id=(\d+)\]/is', '[quote="$1" id="$2" write_time="$3"]', $string);


// 9. تحويل وسم البداية والنهاية من [list] إلى [ul]
$string = preg_replace('/\[list\]/is', '[ul]', $string);
$string = preg_replace('/\[list=[^\]]*\]/is', '[ul]', $string); // يمسك [list=] أو [list=1]
$string = preg_replace('/\[\/list\]/is', '[/ul]', $string);

// 10. تحويل العناصر [*] إلى [li]...[/li]
// نستخدم Regex للبحث عن [*] وما يلحقها حتى نصل لـ [*] أخرى أو نهاية القائمة
$string = preg_replace('/\[\*\](.*?)(?=\s*\[\*\]|\s*\[\/ul\])/is', '[li]$1[/li]', $string);

    return trim($string);
}


}