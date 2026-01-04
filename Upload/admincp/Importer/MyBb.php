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

class MyBb_Importer {
    private $db_source;
    private $db_pbb;
    private $pbb_prefix;
    private $group_styles = [];
    private $post_with_attachments = [];

    public function __construct() {
        // 1. الاتصال بقاعدة بيانات PBBoard (الهدف)
        $config_file = dirname(__DIR__, 2) . '/includes/config.php';
        if (file_exists($config_file)) {
            require($config_file);
            $this->pbb_prefix = $config['db']['prefix'];
            $this->db_pbb = @new mysqli($config['db']['server'], $config['db']['username'], $config['db']['password'], $config['db']['name']);
            $this->db_pbb->set_charset("utf8");
        }

        // 2. الاتصال بقاعدة بيانات MyBB (المصدر)
        $this->db_source = @new mysqli($_SESSION['import_host'], $_SESSION['import_username'], $_SESSION['import_password'], $_SESSION['import_dbname'], $_SESSION['import_port']);
$forced_charset = isset($_SESSION['import_charset']) ? trim($_SESSION['import_charset']) : '';
if (!empty($forced_charset)) {
    $this->db_source->set_charset($forced_charset);
} else {
    $this->db_source->set_charset("utf8mb4"); // الترميز الافتراضي الآمن لهذا السكريبت
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

        // 4. كاش المرفقات
        if ($this->db_source) {
            $src_prefix = $_SESSION['import_tablePrefix'];
            $res_attach = $this->db_source->query("SELECT DISTINCT pid FROM {$src_prefix}attachments WHERE pid > 0");
            if ($res_attach) {
                while ($row_a = $res_attach->fetch_assoc()) {
                    $this->post_with_attachments[$row_a['pid']] = true;
                }
            }
        }
    }

    public function processStep($step, $offset, $limit) {
        $offset = (int)$offset;

        // جلب الإعدادات العامة عند بدء خطوة الأقسام (مرة واحدة فقط)
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
        $res = $this->db_source->query("SELECT name, value FROM {$prefix}settings WHERE name IN ('bbname', 'adminemail', 'boardclosed')");
        if ($res) {
            while ($s = $res->fetch_assoc()) {

                if ($s['name'] == 'bbname') {
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='".$this->escape($s['value'])."' WHERE var_name='title'");
                } elseif ($s['name'] == 'adminemail') {
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='".$this->escape($s['value'])."' WHERE var_name='site_mail'");
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='".$this->escape($s['value'])."' WHERE var_name='admin_email'");
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='localhost' WHERE var_name='smtp_server'");
                } elseif ($s['name'] == 'boardclosed') {
                    $status = ($s['value'] == 1) ? 1 : 0;
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='{$status}' WHERE var_name='board_close'");
                }
            }
        }
    }

private function truncateTargetTable($step) {
    $map = [
        'users' => 'member', 'forums' => 'section', 'threads' => 'subject',
        'posts' => 'reply', 'privateMessages' => 'pm', 'attachments' => 'attach',
        'moderators' => 'moderators', 'polls' => 'poll', 'votes' => 'vote'
    ];

    if (isset($map[$step])) {
        $table = $this->pbb_prefix . $map[$step];

        if ($step == 'users') {
            // الحصول على اسم الآدمن الحالي من الجلسة
            $current_admin = $_SESSION['PowerBB_admin_username'];

            // حذف الجميع باستثناء الآدمن الحالي
            $this->db_pbb->query("DELETE FROM $table WHERE username != '" . $this->escape($current_admin) . "'");

            // تصفير العداد التلقائي
            $this->db_pbb->query("ALTER TABLE $table AUTO_INCREMENT = 1");
        } else {
            $this->db_pbb->query("TRUNCATE TABLE $table");
        }
    }
}

    private function insertIntoPBB($step, $row) {
        switch ($step) {
            case 'forums':
                $linksection = (!empty($row['linkto'])) ? 1 : 0;
                $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}section SET
                    id='{$row['fid']}', title='".$this->escape($row['name'])."',
                    section_describe='".$this->escape($row['description'])."',
                    last_date='{$row['lastpost']}',
                    last_writer='{$row['lastposter']}',
                    last_reply='{$row['lastposttid']}',
                    last_time='{$row['lastpost']}',
                    last_subject='".$this->escape($row['lastpostsubject'])."',
                    last_subjectid='{$row['lastposttid']}',
                    linksite='".$this->escape($row['linkto'])."',
                    section_password='{$row['password']}',
                    linksection='{$linksection}',
                    sort='{$row['fid']}',
                    subject_num='{$row['threads']}',
                    reply_num='{$row['posts']}',
                    parent='{$row['pid']}',
                    use_power_code_allow = '1',
			        show_sig             = '1',
			        icon                 = 'look/images/icons/i1.gif',
			        sectionpicture_type  = '2',
			        subject_order        = '1',
			        use_section_picture  = '0',
			        sec_section          = '0',
			        review_subject       = '0',
			        trash                = '0'");
                break;

case 'users':
    // 1. خريطة المجموعات ومعالجة الستايل
    $group_map = [4 => 1, 2 => 4, 6 => 3, 7 => 6, 3 => 2];
    $group = isset($group_map[$row['usergroup']]) ? $group_map[$row['usergroup']] : 4;
    $style = isset($this->group_styles[$group]) ? $this->group_styles[$group] : '[username]';

    $clean_username = $this->cleanMessage($row['username']);
    $current_admin_username = $_SESSION['PowerBB_admin_username'];

    // 2. منطق فحص الآدمن وتشابه الأسماء
    $is_me = false;
    if ($clean_username == $current_admin_username) {
        // التحقق من البريد الإلكتروني للتأكد أنه نفس الشخص
        if ($row['email'] == $_SESSION['PowerBB_admin_email']) {
            $is_me = true;
        } else {
            // اسم مشابه ولكن لشخص مختلف
            $clean_username .= "1";
        }
    }

    $username_style_cache = str_replace('[username]', $clean_username, $style);

    // 3. معالجة التوقيع والأفاتار
    $row['signature'] = $this->cleanMessage($row['signature']);
    $new_avatar_path = "";
    if (!empty($row['avatar'])) {
        $avatar_filename = basename(explode('?', $row['avatar'])[0]);
        $source_avatar = rtrim($_SESSION['import_uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $avatar_filename;
        $target_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'download' . DIRECTORY_SEPARATOR . 'avatar' . DIRECTORY_SEPARATOR;

        if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);

        if (file_exists($source_avatar) && @copy($source_avatar, $target_dir . $avatar_filename)) {
            $new_avatar_path = 'download/avatar/' . $avatar_filename;
        }
    }

    // 4. تجهيز البيانات للإدخال/التحديث
    $esc_username = $this->escape($clean_username);
    $esc_email    = $this->escape($row['email']);
    $esc_sig      = $this->escape($row['signature']);
    $esc_avatar   = $this->escape($new_avatar_path);
    $esc_style_ch = $this->escape($username_style_cache);

    $extra_fields = "
        username_style_cache  = '{$esc_style_ch}',
        usergroup             = '{$group}',
        posts                 = '{$row['postnum']}',
        lastvisit             = '{$row['lastvisit']}',
        register_date         = '{$row['regdate']}',
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
        reputation            = '{$row['reputation']}',
        pm_window             = '1',
        profile_viewers       = '1',
        user_gender           = 'm',
        lang                  = '1'";

    if ($is_me) {
        // تحديث بيانات الآدمن في مكانه (نحافظ على الباسورد والملح الحالي في PBB)
        $session_pass = $this->escape($_SESSION['PowerBB_admin_password']);
        $session_salt = $this->escape($_SESSION['active_number']);

        $this->db_pbb->query("UPDATE {$this->pbb_prefix}member SET
            email         = '{$esc_email}',
            password      = '{$session_pass}',
            active_number = '{$session_salt}',
            $extra_fields
            WHERE username = '".$this->escape($current_admin_username)."'");

        // تحديث الجلسة بالاسم الجديد في حال تم تنظيفه
        $_SESSION['PowerBB_admin_username'] = $clean_username;
    } else {
        // إدخال الأعضاء الآخرين
        $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}member SET
            id            = '{$row['uid']}',
            username      = '{$esc_username}',
            password      = '".$this->escape($row['password'])."',
            active_number = '".$this->escape($row['salt'])."',
            email         = '{$esc_email}',
            $extra_fields");
    }
    break;

			case 'moderators':
			    $mod_username = !empty($row['username']) ? $row['username'] : 'User_ID_' . $row['id'];
    			$mod_uid = !empty($row['uid']) ? $row['uid'] : $row['id'];

			    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}moderators SET
			        id         = '{$row['mid']}',
			        section_id = '{$row['fid']}',
			        member_id  = '".$mod_uid."',
			        username   = '".$this->escape($mod_username)."'");
			    break;


                $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}member SET
                    id='{$row['uid']}', username='".$this->escape($row['username'])."',
                    username_style_cache='".$this->escape($username_style_cache)."',
                    email='".$this->escape($row['email'])."', password='{$row['password']}',
                    active_number='{$row['salt']}', usergroup='{$group}',
                    avater_path='".$this->escape($new_avatar_path)."', lastvisit='{$row['lastvisit']}',
                    posts='{$row['postnum']}', lastpost_time='{$row['lastpost']}',
                    user_sig='".$this->escape($row['signature'])."', register_date='{$row['regdate']}'");
                break;

            case 'threads':
                $visible = ($row['visible'] == 1) ? 0 : 1;
                $attach_subject = isset($this->post_with_attachments[$row['pid']]) ? 1 : 0;
                $row['message'] = $this->cleanMessage($row['message']);

                $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}subject SET
                    id='{$row['tid']}',
                    title='".$this->escape($row['subject'])."',
                    text='".$this->escape($row['message'])."',
                    section='{$row['fid']}',
                    writer='".$this->escape($row['username'])."',
                    native_write_time='{$row['dateline']}',
                    poll_subject='{$row['poll']}',
                    stick='{$row['sticky']}',
                    close='{$row['closed']}',
                    review_subject='{$visible}',
                    visitor='{$row['views']}',
                    icon='look/images/icons/i1.gif',
                    reply_number='{$row['replies']}',
                    last_replier='".$this->escape($row['lastposter'])."',
                    attach_subject='{$attach_subject}',
                    write_time='{$row['dateline']}'");
                break;

            case 'posts':
                $visible = ($row['visible'] == 1) ? 0 : 1;
                $attach_reply = isset($this->post_with_attachments[$row['pid']]) ? 1 : 0;
                $row['message'] = $this->cleanMessage($row['message']);
                $action_by = ($row['edituid'] > 0) ? $this->escape($row['username']) : '';

                $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}reply SET
                    id='{$row['pid']}',
                    subject_id='{$row['tid']}',
                    title='".$this->escape($row['subject'])."',
                    text='".$this->escape($row['message'])."',
                    writer='".$this->escape($row['username'])."',
                    attach_reply='{$attach_reply}',
                    review_reply='{$visible}',
                    section       = '{$row['fid']}',
                    icon='look/images/icons/i1.gif',
                    actiondate='{$row['edittime']}',
                    reason_edit='".$this->escape($row['editreason'])."',
                    action_by='".$action_by."',
                    write_time='{$row['dateline']}'");
                break;

            case 'privateMessages':
                $folder = ($row['folder'] == 1) ? "inbox" : "sent";
				$row['message'] = $this->cleanMessage($row['message']);
                $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}pm SET
                    title='".$this->escape($row['subject'])."', text='".$this->escape($row['message'])."',
                    date='{$row['dateline']}', icon='look/images/icons/i1.gif', folder='{$folder}', user_read='".($row['readtime'] > 0 ? 1 : 0)."',
                    user_from='".$this->escape($row['from_name'])."', user_to='".$this->escape($row['to_name'])."'");
                break;

            case 'attachments':
                $subject_id = ($row['replyto'] == 0) ? $row['tid'] : $row['pid'];
                $reply = ($row['replyto'] == 0) ? 0 : 1;
                $source_file = rtrim($_SESSION['import_uploads_path'], '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $row['attachname']);
                $new_filename = basename($row['attachname']);
                $target_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'download' . DIRECTORY_SEPARATOR;
                if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
                if (file_exists($source_file)) @copy($source_file, $target_dir . $new_filename);

                $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}attach SET
                    id='{$row['aid']}',
                    filename='".$this->escape($row['filename'])."',
                    filepath='download/".$this->escape($new_filename)."',
                    filesize='{$row['filesize']}',
                    subject_id='{$subject_id}',
                    visitor='{$row['downloads']}',
                    reply='{$reply}',
                    u_id='{$row['uid']}',
                    time='{$row['dateuploaded']}',
                    extension='{$this->_Get_Extension($row['filename'])}'");
                break;

            case 'polls':
                $options_array = array_map('trim', explode('||~|~||', $row['options']));
                $json_answers = json_encode($options_array, JSON_UNESCAPED_UNICODE);
                $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}poll SET
                    id='{$row['pid']}', qus='".$this->escape($row['question'])."',
                    answers='".$this->escape($json_answers)."', subject_id='{$row['tid']}'");
                break;

			case 'votes':
			    // 1. حساب رقم الخيار: MyBB يبدأ العد من 1، بينما PBBoard يبدأ من 0
			    $answer_number = (int)$row['voteoption'] - 1;
			    if ($answer_number < 0) $answer_number = 0; // حماية من القيم السالبة

			    // 2. معالجة اسم المستخدم: إذا كان العضو محذوفاً أو زائراً
			    $username = !empty($row['username']) ? $row['username'] : 'Guest';

			    // 3. معالجة عنوان الـ IP: MyBB يخزنه بصيغة Binary (0x...)
			    // نقوم بتحويله لنص مقروء، وإذا فشل نضع قيمة افتراضية
			    $ip = '127.0.0.1';
			    if (!empty($row['ipaddress'])) {
			        $ip = (strlen($row['ipaddress']) == 4 || strlen($row['ipaddress']) == 16)
			              ? @inet_ntop($row['ipaddress'])
			              : $row['ipaddress'];
			    }
			    // في حال كان الـ IP لا يزال غير صالح بعد التحويل
			    if (!$ip) $ip = '127.0.0.1';

			    // 4. الإدخال في قاعدة بيانات PBBoard
			    $this->db_pbb->query("INSERT INTO {$this->pbb_prefix}vote SET
			        id              = '{$row['vid']}',
			        poll_id         = '{$row['pid']}',
			        member_id       = '{$row['uid']}',
			        answer_number   = '{$answer_number}',
			        votes           = '1',
			        subject_id      = '{$row['tid']}', -- تم جلبه عبر الـ JOIN في fetchFromSource
			        user_ip         = '".$this->escape($ip)."',
			        username        = '".$this->escape($username)."'");
			    break;
        }
    }

    private function fetchFromSource($step, $offset, $limit) {
        $prefix = $_SESSION['import_tablePrefix'];
        switch ($step) {
            case 'forums': return $this->db_source->query("SELECT * FROM {$prefix}forums LIMIT $offset, $limit")->fetch_all(MYSQLI_ASSOC);
            case 'users': return $this->db_source->query("SELECT * FROM {$prefix}users LIMIT $offset, $limit")->fetch_all(MYSQLI_ASSOC);
			case 'threads':
			    return $this->db_source->query("
			        SELECT
			            p.*,
			            t.subject, t.fid, t.dateline, t.replies, t.views,
			            t.sticky, t.closed, t.visible, t.lastposter, t.poll
			        FROM {$prefix}posts p
			        INNER JOIN {$prefix}threads t ON p.tid = t.tid
			        WHERE p.replyto = 0
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);
            case 'posts': return $this->db_source->query("SELECT * FROM {$prefix}posts WHERE replyto>0 LIMIT $offset, $limit")->fetch_all(MYSQLI_ASSOC);
            case 'privateMessages': return $this->db_source->query("SELECT pm.*, u_from.username AS from_name, u_to.username AS to_name FROM {$prefix}privatemessages pm LEFT JOIN {$prefix}users u_from ON u_from.uid = pm.fromid LEFT JOIN {$prefix}users u_to ON u_to.uid = pm.toid LIMIT $offset, $limit")->fetch_all(MYSQLI_ASSOC);
            case 'attachments': return $this->db_source->query("SELECT a.*, p.tid, p.replyto FROM {$prefix}attachments a LEFT JOIN {$prefix}posts p ON a.pid = p.pid LIMIT $offset, $limit")->fetch_all(MYSQLI_ASSOC);
            case 'moderators': return $this->db_source->query("SELECT m.*, u.username, u.uid FROM {$prefix}moderators m LEFT JOIN {$prefix}users u ON u.uid = m.id LIMIT $offset, $limit")->fetch_all(MYSQLI_ASSOC);
            case 'polls': return $this->db_source->query("SELECT * FROM {$prefix}polls LIMIT $offset, $limit")->fetch_all(MYSQLI_ASSOC);
			case 'votes':
			    // نجلب الأصوات مع رقم الموضوع (tid) من جدول polls
			    return $this->db_source->query("
			        SELECT v.*, u.username, p.tid
			        FROM {$prefix}pollvotes v
			        LEFT JOIN {$prefix}users u ON u.uid = v.uid
			        LEFT JOIN {$prefix}polls p ON p.pid = v.pid
			        LIMIT $offset, $limit
			    ")->fetch_all(MYSQLI_ASSOC);

            default: return [];
        }
    }

    public function countSourceRecords($step) {
        $prefix = $_SESSION['import_tablePrefix'];
        $map = [
            'forums' => "{$prefix}forums", 'users' => "{$prefix}users", 'threads' => "{$prefix}posts WHERE replyto=0",
            'posts' => "{$prefix}posts WHERE replyto>0", 'privateMessages' => "{$prefix}privatemessages",
            'attachments' => "{$prefix}attachments", 'moderators' => "{$prefix}moderators",
            'polls' => "{$prefix}polls", 'votes' => "{$prefix}pollvotes"
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

	private function cleanMessage($string) {
	    // 1. تعريف الروابط (استبدل الرابط برابط موقعك الجديد)
	    $site_url = 'http://' . $_SERVER['HTTP_HOST'] . '/'; // أو ضع رابط منتداك مباشرة

	    // 2. تنظيف الهروب الزائد (Slashes)
	    $string = stripslashes($string);

	    // 3. توحيد حالة أحرف أوسمة الروابط
	    $string = str_ireplace('[url', '[URL', $string);
	    $string = str_ireplace('[/url]', '[/URL]', $string);

	    // 4. معالجة المحاذاة BBCode (التي أضفناها سابقاً)
	    $string = preg_replace('/\[align=(center|right|left)\](.*?)\[\/align\]/is', '[$1]$2[/$1]', $string);

	    // 5. تصحيح الروابط الداخلية (MyBB to PBBoard)
	    // إذا كانت الروابط في MyBB بصيغة showthread.php و forumdisplay.php
	    $string = str_replace('showthread.php?tid=', 'index.php?page=topic&show=1&id=', $string);
	    $string = str_replace('forumdisplay.php?fid=', 'index.php?page=forum&show=1&id=', $string);
	    $string = str_replace('member.php?action=profile&uid=', 'index.php?page=profile&show=1&id=', $string);
	    $string = str_replace('attachment.php?aid=', 'index.php?page=download&attach=1&id=', $string);
	    $string = str_replace('pid=', 'id=', $string);
	    $string = str_replace('#pid', '#id', $string);

	    // 6. إصلاح الروابط المكسورة التي تبدأ بـ t أو f مباشرة (كما في الكود القديم)
	    $string = str_replace('[URL]t', '[URL]' . $site_url . 'index.php?page=topic&show=1&id=', $string);
	    $string = str_replace('[URL]f', '[URL]' . $site_url . 'index.php?page=forum&show=1&id=', $string);

		// 7. تحويل وسم الاقتباس من MyBB إلى PBBoard
		// من: [quote="admin" pid="7" dateline="1766871073"]
		// إلى: [quote="admin" id="7" write_time="1766871073"]
		$string = preg_replace(
		    '/\[quote\s*=\s*"([^"]+)"\s+pid\s*=\s*"(\d+)"\s+dateline\s*=\s*"(\d+)"\]/is',
		    '[quote="$1" id="$2" write_time="$3"]',
		    $string
		);

	// 2. تصغير أحرف أوسمة BBCode فقط (لضمان عمل الاستبدالات التالية بدقة)
	    // سيحول [B] إلى [b] و [URL=... ] إلى [url=... ] دون المساس بمحتوى الرابط
	    $string = preg_replace_callback('/\[(\/?)([a-z0-9]+)([^\]]*)\]/i', function($matches) {
	        return "[" . $matches[1] . strtolower($matches[2]) . $matches[3] . "]";
	    }, $string);

	    return $string;
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