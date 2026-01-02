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
define('IN_ADMIN',true);
@session_start();
define('DONT_STRIPS_SLIASHES',true);
$CALL_SYSTEM			        =	array();
$CALL_SYSTEM['ADDONS']         =   true;
$CALL_SYSTEM['HOOKS']         =   true;
$CALL_SYSTEM['STYLE'] 	= 	true;
$CALL_SYSTEM['TEMPLATE'] 	= 	true;
$CALL_SYSTEM['TEMPLATESEDITS'] 	= 	true;
define('JAVASCRIPT_PowerCode',true);
define('CLASS_NAME','PowerBBCoreMOD');
@set_time_limit(0);
@ini_set('memory_limit', '512M');
include('../common.php');
class PowerBBCoreMOD
{
	function run()
	{
		global $PowerBB;

		if ($PowerBB->_CONF['member_permission'])
		{
			if ($PowerBB->_GET['ajax'] == '1')
			{
			    $this->handleAjaxRequest();
			    exit;
			}

			$PowerBB->template->display('header');
			if ($PowerBB->_CONF['rows']['member_row']['usergroup'] != '1')
			{
			  $PowerBB->functions->error($PowerBB->_CONF['template']['_CONF']['lang']['error_permission']);
			}


			if ($PowerBB->_GET['import'])
			{
				if ($PowerBB->_GET['main'])
				{
					$this->_importMain();
				}
				elseif ($PowerBB->_GET['data'])
				{
					$this->_dataMain();
				}
				elseif ($PowerBB->_GET['config'])
				{
					$this->_configMain();
				}
				elseif ($PowerBB->_GET['step-config'])
				{
					$this->stepConfig();
				}
			}
		$PowerBB->template->display('footer');
		}

	}


	function _importMain()
	{
		global $PowerBB;

		$PowerBB->template->display('import');

    }

	function _dataMain()
	{
	    global $PowerBB;

	    if (empty($PowerBB->_POST['importer']))
	    {
	        $PowerBB->functions->error($PowerBB->_CONF['template']['_CONF']['lang']['Please_fill_in_all_the_information']);
	    }

	    $importer_name = str_replace('PBBI:', "", $PowerBB->_POST['importer']);
	    $_SESSION['importer'] = $importer_name;

	    // جلب المسار الحالي للمنتدى لتسهيل الأمر على المستخدم
	    // dirname(dirname(__FILE__)) يعيد المسار الرئيسي لـ PBBoard
	    $current_pbb_path = dirname(dirname(dirname(__FILE__)));
	    $PowerBB->template->assign('current_path', $current_pbb_path);

	    if(strstr($importer_name, 'MyBb'))
	    {
	        $PowerBB->template->assign('uploads_path_title', "Mybb Path");
			$PowerBB->template->assign('prefix', "mybb_");
	    }
	    if(strstr($importer_name, 'PBBoard'))
	    {
	        $PowerBB->template->assign('uploads_path_title', "PBBoard Path");
			$PowerBB->template->assign('prefix', "pbb_");
	    }

	    if(strstr($importer_name, 'PhpBb'))
	    {
	        $PowerBB->template->assign('uploads_path_title', "phpBB Path");
			$PowerBB->template->assign('prefix', "phpbb_");
	    }

	    if(strstr($importer_name, 'vBulletin'))
	    {
	        $PowerBB->template->assign('uploads_path_title', "vBulletin Path");
			$PowerBB->template->assign('prefix', "");
	    }

	    if($importer_name == 'XenForo2')
	    {
	        $PowerBB->template->assign('uploads_path_title', "XenForo Path");
	        $PowerBB->template->assign('prefix', "xf_");
	    }


		$PowerBB->template->assign('path_helper_text', "هذا المسار ضروري لنقل المرفقات، الصور الرمزية، والبيانات الداخلية");
	    $PowerBB->template->assign('Configuretitleimport', $importer_name);


	    $PowerBB->template->display('import_config');
	}

function _configMain()
	{
		global $PowerBB;

		if (empty($PowerBB->_POST['host']))
		{
			$PowerBB->functions->error("Please enter a <b>MySQL server</b>");
		}

		if (empty($PowerBB->_POST['username']))
		{
			$PowerBB->functions->error(" Please enter a <b>database username</b>");
		}

		if (empty($PowerBB->_POST['dbname']))
		{
			$PowerBB->functions->error(" Please enter a <b>database name</b></b>");
		}

        // --- الجزء المضاف لاستقبال الـ Limit الجديد ---
        // نضع قيمة 1000 كافتراضي إذا كان الحقل فارغاً
        $_SESSION['import_limit'] = (!empty($PowerBB->_POST['import_limit'])) ? (int)$PowerBB->_POST['import_limit'] : 1000;
        // ---------------------------------------------

        $_SESSION['import_host'] = $PowerBB->_POST['host'];
        $_SESSION['import_password'] = $PowerBB->_POST['password'];
        $_SESSION['import_username'] = $PowerBB->_POST['username'];
        $_SESSION['import_dbname'] = $PowerBB->_POST['dbname'];
        $_SESSION['import_port'] = $PowerBB->_POST['port'];
        $_SESSION['import_tablePrefix'] = $PowerBB->_POST['tablePrefix'];
        $_SESSION['import_uploads_path'] = $PowerBB->_POST['uploads_path'];
        $_SESSION['import_charset'] = $PowerBB->_POST['charset'];

        if (!isset($_SESSION['ORIGINAL_ADMIN_NAME'])) {
            $_SESSION['ORIGINAL_ADMIN_NAME'] = $_SESSION['PowerBB_admin_username'];
        }

        if (!isset($_SESSION['active_number']) || empty($_SESSION['active_number']) ) {
            $admin_user = $_SESSION['PowerBB_admin_username'];
            $sql = "SELECT active_number FROM " . $PowerBB->table['member'] . " WHERE username='" . $admin_user . "' LIMIT 1";
            $get_admin = $PowerBB->DB->sql_fetch_array($PowerBB->DB->sql_query($sql));

            if ($get_admin && !empty($get_admin['active_number'])) {
                $_SESSION['active_number'] = $get_admin['active_number'];
            } else {
                $_SESSION['active_number'] = '12345678';
            }
        }

        if (!isset($_SESSION['PowerBB_admin_email']) || empty($_SESSION['PowerBB_admin_email']) ) {
            $admin_user = $_SESSION['PowerBB_admin_username'];
            $sql = "SELECT email FROM " . $PowerBB->table['member'] . " WHERE username='" . $admin_user . "' LIMIT 1";
            $get_admin = $PowerBB->DB->sql_fetch_array($PowerBB->DB->sql_query($sql));

            if ($get_admin && !empty($get_admin['email'])) {
                $_SESSION['PowerBB_admin_email'] = $get_admin['email'];
            }
        }



        $PowerBB->template->assign('import_data', 1);
        $PowerBB->template->assign('Configuretitleimport', $_SESSION['importer']);
        $PowerBB->template->assign('host', $PowerBB->_POST['host']);
        $PowerBB->template->assign('password', $PowerBB->_POST['password']);
        $PowerBB->template->assign('username', $PowerBB->_POST['username']);
        $PowerBB->template->assign('dbname', $PowerBB->_POST['dbname']);
        $PowerBB->template->assign('port', $PowerBB->_POST['port']);
        $PowerBB->template->assign('tablePrefix', $PowerBB->_POST['tablePrefix']);
        $PowerBB->template->assign('uploads_path', $PowerBB->_POST['uploads_path']);
        $PowerBB->template->assign('charset', $PowerBB->_POST['charset']);

        // تمرير قيمة الـ Limit للقالب ليظهر في الخطوة التالية داخل الـ JavaScript
        $PowerBB->template->assign('selected_limit', $_SESSION['import_limit']);

        $PowerBB->template->display('import_config');
	}

    function stepConfig()
	{
	    global $PowerBB;

	    if (empty($PowerBB->_POST['steps'])) {
	        $PowerBB->functions->error("يرجى اختيار قسم واحد على الأقل للاستيراد");
	    }

	    // الترتيب الصحيح للعمليات
	    $logic_order = ['users', 'forums', 'moderators', 'threads', 'posts', 'privateMessages', 'polls', 'votes', 'attachments'];
	    $selected = $PowerBB->_POST['steps'];

	    $final_steps = [];
	    foreach ($logic_order as $step) {
	        if (in_array($step, $selected)) {
	            $final_steps[] = $step;
	        }
	    }

		$limit_value = (isset($_SESSION['import_limit'])) ? (int)$_SESSION['import_limit'] : 1000;
        $PowerBB->template->assign('selected_limit', $limit_value);

	    $PowerBB->template->assign('js_steps', json_encode($final_steps));
	    $PowerBB->template->display('import_step');
	}

	private function handleAjaxRequest()
	{
	    global $PowerBB;

	    // تنظيف المخرجات لضمان إرسال JSON سليم
	    if (ob_get_length()) ob_clean();
	    header('Content-Type: application/json');

	    try {
	        // اسم النظام المختار، مثلاً: MyBb
	        $importerType = $_SESSION['importer'];

	        $file = dirname(__DIR__) . '/Importer/' . $importerType . '.php';

	        if (!file_exists($file)) {
	            throw new Exception("ملف الاستيراد غير موجود: " . $importerType);
	        }

	        require_once($file);

	        // هنا السحر: استدعاء الكلاس ديناميكياً
	        // إذا كان النوع MyBb سيصبح اسم الكلاس MyBb_Importer
	        $className = $importerType . '_Importer';

	        if (!class_exists($className)) {
	            throw new Exception("الكلاس {$className} غير موجود في الملف.");
	        }

	        // إنشاء كائن من الكلاس مهما كان نوعه
	        $importer = new $className();

	        // تنفيذ الخطوة
	        $step   = $PowerBB->_POST['step'];
	        $offset = (int)$PowerBB->_POST['offset'];
	        $limit  = (int)$PowerBB->_POST['limit'];

	        $result = $importer->processStep($step, $offset, $limit);

	        echo json_encode($result);

	    } catch (Exception $e) {
	        echo json_encode([
	            'status' => 'error',
	            'message' => $e->getMessage()
	        ]);
	    }
	    exit;
	}


}

?>
