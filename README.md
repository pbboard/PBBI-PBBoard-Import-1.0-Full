============================================================
PBBI PBBoard Importer - Version 1.0.0
============================================================

Plugin Description:
An advanced data migration system designed for PBBoard, enabling 
seamless data import from various global forum platforms (vBulletin, 
XenForo, MyBB, phpBB) into your PBBoard database.

------------------------------------------------------------
1. Technical Requirements:
------------------------------------------------------------
* PBBoard Version: 3.4 or higher.
* PHP Version: 8.0 or newer.
* Database: MySQL 4.1.3+ (MySQLi extension required).
* Browser: A modern web browser is recommended for optimal 
  jQuery/AJAX processing.

------------------------------------------------------------
2. Supported Platforms in this Version:
------------------------------------------------------------
* vBulletin: Versions 3.x, 4.x, 5.x
* XenForo: Versions 2.x
* phpBB: Versions 3.x
* MyBB: Versions 1.x

------------------------------------------------------------
3. Installation Instructions:
------------------------------------------------------------
A- File Upload:
   Upload the contents of the 'Upload' folder to your PBBoard 
   root directory on the server.

B- Plugin Import:
   1. Log in to your Admin Control Panel (ACP).
   2. Navigate to the sidebar: Plugins -> Import New Plugin.
   3. Upload the (PBBI-PBBoard-Import-1.0.xml) file.

------------------------------------------------------------
4. Migration Steps (Usage):
------------------------------------------------------------
1. In the ACP, navigate to the new "Import System" section.
2. Select the "Source Forum" type.
3. Enter the database credentials for the source forum 
   (Host, DB Name, User, Password).
4. Provide the server absolute path for the source forum 
   directory (required for migrating attachments and avatars).
5. Select the data tables you wish to import (Members, Threads, etc.).
6. Set the "Batch Processing Limit" (Limit):
   - Shared Hosting: 100 - 500
   - Dedicated/VPS: 1000 - 5000
7. Click 'Continue' and monitor the real-time progress bar.

------------------------------------------------------------
5. Post-Import Optimization:
------------------------------------------------------------
Once the migration is complete, perform the following steps 
to ensure data integrity:
- Go to: ACP -> Maintenance -> Update Counters.
- Execute: (Update all forums at once - Auto-update permissions 
  for all groups and forums).

------------------------------------------------------------
6. Important Notes:
------------------------------------------------------------
* Always perform a full database backup before starting the 
  import process.
* For attachment migration, ensure folder permissions (CHMOD) 
  are correctly set to allow read/write access.
* Password hashing methods vary between platforms. If a user 
  cannot log in, please advise them to use the "Forgot Password" 
  feature.

------------------------------------------------------------
Credits:
Developed by: [SULAIMAN DAWOOD]
Dedicated to supporting the PBBoard community.
Official Website: https://pbboard.info
Created on: 01/01/2026
============================================================
