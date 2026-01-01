<?php
(!defined('IN_PowerBB')) ? die() : '';

class vBulletin5_Importer {
    private $db_source;
    private $db_pbb;
    private $pbb_prefix;
    private $group_styles = [];
    private $vb_url = '';
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

        // 2. الاتصال بقاعدة بيانات vBulletin 5 (المصدر)
        $this->db_source = @new mysqli($_SESSION['import_host'], $_SESSION['import_username'], $_SESSION['import_password'], $_SESSION['import_dbname'], $_SESSION['import_port']);
        // vB5 غالباً latin1 ولكننا سنجرب utf8mb4 للتوافق، وإذا كانت البيانات مشفرة سنتعامل معها في smartClean
$forced_charset = isset($_SESSION['import_charset']) ? trim($_SESSION['import_charset']) : '';
if (!empty($forced_charset)) {
    $this->db_source->set_charset($forced_charset);
} else {
    // نستخدم latin1 لكي تصلنا الرموز كما هي دون "تحسين" خاطئ من MySQL
    // ثم نقوم نحن بمعالجتها في smartClean
    $this->db_source->set_charset("latin1");
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
    $prefix = $_SESSION['import_prefix'];

    // نحدد المتغيرات التي نريد جلبها من vBulletin
    $settings_to_get = "'bbtitle', 'webmasteremail', 'bbactive'";
    $res = $this->db_source->query("SELECT varname, value FROM {$prefix}setting WHERE varname IN ($settings_to_get)");

    if ($res) {
        while ($s = $res->fetch_assoc()) {
            switch ($s['varname']) {
                case 'bbtitle':
                    // تنظيف وتحويل اسم المنتدى للغة العربية
                    $title = $this->smartClean($s['value']);
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='".$this->escape($title)."' WHERE var_name='title'");
                    break;

                case 'webmasteremail':
                    // تحديث بريد الإدارة في أكثر من مكان كما في PBBoard
                    $email = $this->escape($s['value']);
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='{$email}' WHERE var_name='admin_email'");
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='{$email}' WHERE var_name='send_email'");
                    // ضبط SMTP ليكون محلياً كافتراضي
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='localhost' WHERE var_name='smtp_server'");
                    break;

                case 'bbactive':
                    // في vB: 1 تعني مفعل، 0 تعني مغلق. في PBBoard: 1 تعني مغلق، 0 مفعل (عكس بعض)
                    $status = ($s['value'] == 0) ? 1 : 0;
                    $this->db_pbb->query("UPDATE {$this->pbb_prefix}info SET value='{$status}' WHERE var_name='board_close'");
                    break;
            }
        }
    }
}

    private function loadExchangeUrls() {
        if (!$this->db_source) return;
        $prefix = $_SESSION['import_tablePrefix'];

        $settings_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'settings.php';
        if (file_exists($settings_path)) {
            require($settings_path);
            $this->pbb_url = rtrim($setting['forum_url'], '/') . '/';
        }

        $res = $this->db_source->query("SELECT value FROM {$prefix}setting WHERE varname = 'bburl'");
        if ($res && $row = $res->fetch_assoc()) {
            $this->vb_url = rtrim($row['value'], '/') . '/';
        }
    }

private function truncateTargetTable($step) {
    $map = [
        'users'           => 'member',
        'forums'          => 'section',
        'threads'         => 'subject',
        'posts'           => 'reply',
        'privateMessages' => 'pm',
        'attachments'     => 'attach',
        'moderators'      => 'moderators',
        'polls'           => 'poll',
        'votes'           => 'vote'
    ];

    if (isset($map[$step])) {
        $table = $this->pbb_prefix . $map[$step];

        if ($step == 'users') {
            // الحصول على اسم الآدمن الحالي من الجلسة
            $current_admin = $_SESSION['PowerBB_admin_username'];

            // حذف كل الأعضاء ما عدا الآدمن الذي يقوم بالعملية حالياً
            $this->db_pbb->query("DELETE FROM $table WHERE username != '" . $this->escape($current_admin) . "'");

            // تصفير العداد التلقائي ليبدأ من جديد مع الحفاظ على الآدمن
            $this->db_pbb->query("ALTER TABLE $table AUTO_INCREMENT = 1");

        } else {
            $this->db_pbb->query("TRUNCATE TABLE $table");
        }
    }
}

    private function insertIntoPBB($step, $row) {
        switch ($step) {
case 'forums':
    $parentid = (string)$row['parentid'];
    $parentid = str_ireplace("-1", "0", $parentid);

    $title = $this->smartClean(strip_tags($row['title']));
    $description = $this->smartClean($row['description']);
    $lastposter = $this->smartClean($row['lastposter']);
    $lastthread = $this->smartClean($row['lastthread']);

    $linksection = empty($row['link']) ? '0' : '1';

    if ($parentid == '0') {
        // القسم الرئيسي (الفئة)
        $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}section SET
            id                   = '{$row['forumid']}',
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
            linksection          = '',
            sort                 = '{$row['forumid']}',
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
        // المنتديات الفرعية
        $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}section SET
            id                   = '{$row['forumid']}',
            title                = '".$this->escape($title)."',
            section_describe     = '".$this->escape($description)."',
            last_date            = '{$row['lastpost']}',
            last_writer          = '".$this->escape($lastposter)."',
            last_reply           = '{$row['lastpostid']}',
            last_time            = '{$row['lastpost']}',
            last_subject         = '".$this->escape($lastthread)."',
            last_subjectid       = '{$row['lastthreadid']}',
            linksite             = '".$this->escape($row['link'])."',
            section_password     = '".$this->escape($row['password'])."',
            linksection          = '{$linksection}',
            sort                 = '{$row['displayorder']}',
            subject_num          = '{$row['threadcount']}',
            reply_num            = '{$row['replycount']}',
            subject_order        = '1',
            show_sig             = '1',
            use_power_code_allow = '1',
            review_subject       = '0',
            sectionpicture_type  = '2',
            icon                 = 'look/images/icons/i1.gif',
            last_berpage_nm      = '{$row['replycount']}',
            parent               = '{$parentid}'");
    }
    break;

case 'users':
    $group_map = [6 => 1, 2 => 4, 8 => 6, 5 => 8, 3 => 5, 7 => 3];
    $group = isset($group_map[$row['usergroupid']]) ? $group_map[$row['usergroupid']] : 4;
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
    // ---------------------------------------

    $username_style_cache = str_replace('[username]', $clean_username, $style);

    // --- معالجة الصورة الرمزية ---
    $new_avatar_path = "";
    if (!empty($row['filedata'])) {
        $extension = pathinfo($row['filename'], PATHINFO_EXTENSION) ?: 'jpg';
        $avatar_name = "avatar_" . $row['userid'] . "_" . time() . "." . $extension;
        $target_path = dirname(__DIR__, 2) . "/download/avatar/" . $avatar_name;

        if (file_put_contents($target_path, $row['filedata'])) {
            $new_avatar_path = 'download/avatar/' . $avatar_name;
        }
    } elseif (!empty($row['filename'])) {
        $source_file = rtrim($_SESSION['import_uploads_path'], '/\\') . DIRECTORY_SEPARATOR . $row['filename'];
        $target_path = dirname(__DIR__, 2) . "/download/avatar/" . $row['filename'];

        if (file_exists($source_file) && @copy($source_file, $target_path)) {
            $new_avatar_path = 'download/avatar/' . $row['filename'];
        }
    }

    $esc_username  = $this->escape($clean_username);
    $esc_pass      = $this->escape($row['password']);
    $esc_salt      = $this->escape($row['salt']);
    $esc_email     = $this->escape($row['email']);
    $esc_sig       = $this->escape($this->smartClean($row['signature']));
    $esc_style_ch  = $this->escape($username_style_cache);
    $esc_avatar    = $this->escape($new_avatar_path);

    $extra_fields = "
        reputation                = '".(int)$row['reputation']."',
        warnings                  = '".(int)$row['warnings']."',
        visitormessage            = '1',
        pm_window                 = '1',
        user_gender               = 'm',
        unread_pm                 = '0',
        send_allow                = '1',
        style_id_cache            = '1',
        should_update_style_cache  = '0',
        usergroup                 = '{$group}',
        posts                     = '".(int)$row['posts']."',
        lastvisit                 = '".(int)$row['lastvisit']."',
        register_date             = '".(int)$row['joindate']."',
        user_sig                  = '{$esc_sig}',
        username_style_cache      = '{$esc_style_ch}',
        avater_path               = '{$esc_avatar}',
        visitor                   = '1',
        style                     = '1',
        lang                      = '1'
    ";

    if ($is_me) {
        // تحديث بيانات الآدمن الحالي في PBBoard ببياناته القادمة من vB
        // لا نغير الـ ID هنا لضمان استقرار القاعدة والجلسة
        $this->db_pbb->query("UPDATE {$this->pbb_prefix}member SET
            email         = '{$esc_email}',
            password      = '{$esc_pass}',
            active_number = '{$esc_salt}',
            $extra_fields
            WHERE username = '{$this->escape($current_admin_username)}'");

        // تحديث الجلسة بالاسم الجديد إذا طرأ عليه تغيير (تنظيف)
        $_SESSION['PowerBB_admin_username'] = $clean_username;
    } else {
        // إدخال مستخدم جديد بشكل طبيعي
        $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}member SET
            id            = '{$row['userid']}',
            username      = '{$esc_username}',
            password      = '{$esc_pass}',
            active_number = '{$esc_salt}',
            email         = '{$esc_email}',
            $extra_fields");
    }
    break;

case 'threads':
    // تجهيز البيانات وتنظيفها
    $title = $this->smartClean($row['title']);
    $text  = $this->smartClean($row['pagetext']);
    $writer = $this->smartClean($row['postusername']);
    $last_replier = $this->smartClean($row['lastposter']);

    // تحديد حالة الإغلاق (في vB: 1=مفتوح، 0=مغلق)
    $is_closed = ($row['open'] == 1) ? 0 : 1;

    // فحص المرفقات (إذا كان الحقل متوفراً في vB)
    $has_attach = (isset($row['attach']) && $row['attach'] > 0) ? 1 : 0;

    // فحص التصويت
    $has_poll = ($row['pollid'] > 0) ? 1 : 0;

    $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}subject SET
        id               = '{$row['threadid']}',
        title            = '".$this->escape($title)."',
        text             = '".$this->escape($text)."',
        section          = '{$row['forumid']}',
        writer           = '".$this->escape($writer)."',
        native_write_time= '{$row['dateline']}',
        write_time       = '{$row['lastpost']}',
        poll_subject     = '{$has_poll}',
        stick            = '{$row['sticky']}',
        close            = '{$is_closed}',
        review_subject   = '0',
        visitor          = '{$row['views']}',
        icon             = 'look/images/icons/i1.gif',
        reply_number     = '{$row['replycount']}',
        last_replier     = '".$this->escape($last_replier)."',
        attach_subject   = '{$has_attach}',
        delete_topic     = '0',
        sec_subject      = '0'");
    break;

case 'posts':
    $message = $this->smartClean($row['pagetext']);
    $writer  = $this->smartClean($row['username']);
    $thread_title = $this->smartClean($row['thread_title']);

    $visible = ($row['visible'] == 1) ? 0 : 1;
    $attach_reply = (isset($row['attach']) && $row['attach'] > 0) ? 1 : 0;

    // معالجة بيانات التعديل (vB يستخدم حقل editdate و editusername)
    $has_edit = (isset($row['editdate']) && $row['editdate'] > 0);
    $action_by = $has_edit ? $this->escape($this->smartClean($row['editusername'])) : '';
    $edit_time = $has_edit ? $row['editdate'] : 0;

    $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}reply SET
        id              = '{$row['postid']}',
        subject_id      = '{$row['threadid']}',
        title           = 'RE: ".$this->escape($thread_title)."',
        text            = '".$this->escape($message)."',
        writer          = '".$this->escape($writer)."',
        attach_reply    = '{$attach_reply}',
        review_reply    = '{$visible}',
        icon            = 'look/images/icons/i1.gif',
        actiondate      = '{$edit_time}',
        reason_edit     = '',
        action_by       = '{$action_by}',
        write_time      = '{$row['dateline']}'");
    break;

case 'privateMessages':
    $title = $this->smartClean($row['title']);
    $message = $this->smartClean($row['message']);
    $from_user = $this->smartClean($row['fromusername']);
    $to_user = $this->smartClean($row['recipient_name']);

    $folder = ($row['folderid'] == 0) ? 'inbox' : 'sent';
    $is_read = ($row['messageread'] == 0) ? '' : $row['messageread'];

    $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}pm SET
        id        = '{$row['pmtextid']}',
        title     = '".$this->escape($title)."',
        text      = '".$this->escape($message)."',
        user_from = '".$this->escape($from_user)."',
        user_to   = '".$this->escape($to_user)."',
        user_read = '{$is_read}',
        date      = '{$row['dateline']}',
        icon      = 'look/images/icons/i1.gif',
        folder    = '{$folder}'");
    break;



case 'moderators':
    // تنظيف اسم المشرف لضمان سلامة اللغة العربية
    $mod_username = $this->smartClean($row['username']);

    // في حال عدم وجود اسم (حالة نادرة)، نستخدم المعرف كما في محول MyBB
    if (empty($mod_username)) {
        $mod_username = 'User_ID_' . $row['userid'];
    }

    $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}moderators SET
        id         = '{$row['moderatorid']}',
        section_id = '{$row['forumid']}',
        member_id  = '{$row['userid']}',
        username   = '".$this->escape($mod_username)."'");
    break;

case 'polls':
    // تنظيف السؤال باستخدام smartClean
    $question = $this->smartClean($row['question']);

    // تقسيم الخيارات (vB يستخدم |||)
    $options_raw = explode("|||", $row['options']);
    $poll_array = [];
    foreach ($options_raw as $opt) {
        $poll_array[] = $this->smartClean($opt);
    }

    // تحويل المصفوفة إلى JSON (أكثر حداثة وتوافقاً من Serialize)
    $json_answers = json_encode($poll_array, JSON_UNESCAPED_UNICODE);

    $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}poll SET
        id         = '{$row['pollid']}',
        qus        = '".$this->escape($question)."',
        answers    = '".$this->escape($json_answers)."',
        subject_id = '{$row['threadid']}'");
    break;

	case 'votes':
    $username = $this->smartClean($row['username']);
    if (empty($username)) $username = 'Guest';

    // vBulletin يبدأ خيار التصويت من 1، PBBoard غالباً يبدأ من 0
    $answer_number = (int)$row['voteoption'] - 1;
    if ($answer_number < 0) $answer_number = 0;

    $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}vote SET
        poll_id       = '{$row['pollid']}',
        member_id     = '{$row['userid']}',
        answer_number = '{$answer_number}',
        votes         = '1',
        subject_id    = '{$row['threadid']}',
        username      = '".$this->escape($username)."'");
    break;

case 'attachments':
    // تجهيز المسارات والملحقات
    $filename = $row['filename'];
    $extension = !empty($row['extension']) ? strtolower($row['extension']) : strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // توليد اسم فريد للملف
    $new_filename = "attach_" . $row['attachmentid'] . "_" . time() . "." . $extension;
    $target_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'download' . DIRECTORY_SEPARATOR;
    $target_path = $target_dir . $new_filename;

    if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);

    $file_saved = false;

    // استخراج الملف من حقل filedata (MediumBlob) كما يظهر في صورتك
    if (!empty($row['filedata'])) {
        if (@file_put_contents($target_path, $row['filedata'])) {
            $file_saved = true;
        }
    }

    // تحديد ما إذا كان المرفق لموضوع (0) أم لرد (1)
    // نتحقق إذا كان رقم المشاركة postid هو نفسه رقم المشاركة الأولى في الموضوع
    if ((int)$row['postid'] === (int)$row['firstpostid']) {
        $subject_id = $row['threadid'];
        $reply = 0;
    } else {
        $subject_id = $row['postid'];
        $reply = 1;
    }

    if ($file_saved && $subject_id > 0) {
        $this->db_pbb->query("INSERT IGNORE INTO {$this->pbb_prefix}attach SET
            id          = '{$row['attachmentid']}',
            filename    = '".$this->escape($filename)."',
            filepath    = 'download/".$this->escape($new_filename)."',
            filesize    = '".($row['filesize'] > 0 ? $row['filesize'] : strlen($row['filedata']))."',
            subject_id  = '{$subject_id}',
            visitor     = '{$row['counter']}',
            reply       = '{$reply}',
            u_id        = '{$row['userid']}',
            time        = '{$row['dateline']}',
            extension   = '".$this->escape($extension)."'");
    }
    break;

        }
    }

    private function fetchFromSource($step, $offset, $limit) {
        $prefix = $_SESSION['import_tablePrefix'];
        switch ($step) {
case 'forums':
    return $this->db_source->query("
        SELECT forumid, title, description, parentid, displayorder,
               replycount, threadcount, lastposter, lastthread,
               lastthreadid, lastpost
        FROM {$prefix}forum
        ORDER BY forumid ASC
        LIMIT $offset, $limit
    ")->fetch_all(MYSQLI_ASSOC);

case 'users':
    $prefix = $_SESSION['import_prefix'];
    $sql = "SELECT u.*, ut.signature, ca.filename, ca.filedata
            FROM {$prefix}user u
            LEFT JOIN {$prefix}usertextfield ut ON u.userid = ut.userid
            LEFT JOIN {$prefix}customavatar ca ON u.userid = ca.userid
            ORDER BY u.userid ASC
            LIMIT $offset, $limit";
    return $this->db_source->query($sql)->fetch_all(MYSQLI_ASSOC);

case 'threads':
    $prefix = $_SESSION['import_prefix'];
    $sql = "SELECT t.*, p.pagetext
            FROM {$prefix}thread t
            INNER JOIN {$prefix}post p ON p.postid = t.firstpostid
            ORDER BY t.threadid ASC
            LIMIT $offset, $limit";
    return $this->db_source->query($sql)->fetch_all(MYSQLI_ASSOC);

case 'posts':
    $prefix = $_SESSION['import_prefix'];
    $sql = "SELECT p.*, t.title as thread_title
            FROM {$prefix}post p
            INNER JOIN {$prefix}thread t ON p.threadid = t.threadid
            WHERE p.postid != t.firstpostid
            ORDER BY p.postid ASC
            LIMIT $offset, $limit";
    return $this->db_source->query($sql)->fetch_all(MYSQLI_ASSOC);

	case 'privateMessages':
    $prefix = $_SESSION['import_prefix'];
    $sql = "SELECT pt.*, p.userid, p.folderid, p.messageread, u.username as recipient_name
            FROM {$prefix}pmtext pt
            INNER JOIN {$prefix}pm p ON pt.pmtextid = p.pmtextid
            LEFT JOIN {$prefix}user u ON p.userid = u.userid
            ORDER BY pt.pmtextid ASC
            LIMIT $offset, $limit";
    return $this->db_source->query($sql)->fetch_all(MYSQLI_ASSOC);


case 'moderators':
    $prefix = $_SESSION['import_prefix'];
    $sql = "SELECT m.*, u.username
            FROM {$prefix}moderator m
            INNER JOIN {$prefix}user u ON m.userid = u.userid
            ORDER BY m.moderatorid ASC
            LIMIT $offset, $limit";
    return $this->db_source->query($sql)->fetch_all(MYSQLI_ASSOC);

case 'polls':
    $prefix = $_SESSION['import_prefix'];
    $sql = "SELECT p.*, t.threadid
            FROM {$prefix}poll p
            LEFT JOIN {$prefix}thread t ON p.pollid = t.pollid
            ORDER BY p.pollid ASC
            LIMIT $offset, $limit";
    return $this->db_source->query($sql)->fetch_all(MYSQLI_ASSOC);

case 'votes':
    $prefix = $_SESSION['import_prefix'];
    // استبدلنا dateline بـ votedate ليتوافق مع قاعدة بيانات vB4
    $sql = "SELECT v.*, u.username, t.threadid, v.votedate as dateline
            FROM {$prefix}pollvote v
            LEFT JOIN {$prefix}user u ON v.userid = u.userid
            LEFT JOIN {$prefix}thread t ON v.pollid = t.pollid
            ORDER BY v.pollvoteid ASC
            LIMIT $offset, $limit";
    return $this->db_source->query($sql)->fetch_all(MYSQLI_ASSOC);

case 'attachments':
    $prefix = $_SESSION['import_prefix'];
    $sql = "SELECT a.attachmentid, a.userid, a.dateline, a.filename, a.filedata,
                   a.counter, a.filesize, a.postid, a.extension, t.threadid, t.firstpostid
            FROM {$prefix}attachment a
            LEFT JOIN {$prefix}post p ON a.postid = p.postid
            LEFT JOIN {$prefix}thread t ON p.threadid = t.threadid
            ORDER BY a.attachmentid ASC
            LIMIT $offset, $limit";
    return $this->db_source->query($sql)->fetch_all(MYSQLI_ASSOC);

	    }
    }

    public function countSourceRecords($step) {
        $prefix = $_SESSION['import_tablePrefix'];
        $map = ['forums' => 'forum', 'users' => 'user', 'threads' => 'thread', 'posts' => 'post', 'privateMessages' => 'pmtext'];
        if (!isset($map[$step])) return 0;
        $res = $this->db_source->query("SELECT COUNT(*) FROM {$prefix}{$map[$step]}");
        return (int)$res->fetch_row()[0];
    }

    private function escape($str) {
        return $this->db_pbb ? $this->db_pbb->real_escape_string($str) : addslashes($str);
    }

private function smartClean($text) {
    if (empty($text)) return '';

	// 1. معالجة التشكيل والترميز (كما فعلنا سابقاً)
    $text = htmlspecialchars_decode($text);
    $text = str_replace(['&#39;', '&quot;', '&lt;', '&gt;'], ["'", '"', '<', '>'], $text);

    // 2. مصفوفة أنماط روابط vBulletin وما يقابلها في PBBoard
    $patterns = [
        '/showthread\.php\?t=(\d+)/i' => 'index.php?page=topic&show=1&id=$1',

        '/forumdisplay\.php\?f=(\d+)/i' => 'index.php?page=forum&show=1&id=$1',

        '/member\.php\?u=(\d+)/i' => 'index.php?page=profile&show=1&id=$1',

        '/attachment\.php\?attachmentid=(\d+)/i' => 'index.php?page=download&attach=1&id=$1'
    ];

    // تنفيذ عملية الاستبدال للروابط
    foreach ($patterns as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text);
    }
    // التحقق من وجود حروف تحتاج تحويل
    if (preg_match('/[\x80-\xff]/', $text)) {
        // التأكد من أن مكتبة iconv مفعلة في السيرفر
        if (function_exists('iconv')) {
            $converted = @iconv('CP1256', 'UTF-8//TRANSLIT', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        // حل احتياطي في حال عدم وجود iconv (للتوافق مع PHP 8+)
        elseif (function_exists('mb_convert_encoding')) {
            $text = @mb_convert_encoding($text, 'UTF-8', 'CP1256');
        }
    }

    // تنظيف الـ BBCode الخاص بـ vBulletin
    $text = preg_replace('/\[(b|i|u|url|img|quote|code|center|color|size|font|list|video)[^\]]*\]/i', '', $text);
    $text = preg_replace('/\[\/(b|i|u|url|img|quote|code|center|color|size|font|list|video)\]/i', '', $text);

    return trim($text);
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