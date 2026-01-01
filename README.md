# 🚀 PBBI: PBBoard Importer (Version 1.0.0)

**PBBI** is an advanced data migration tool specifically built for **PBBoard**. It enables a seamless, reliable, and fast transition from major forum platforms into your PBBoard database.

---

## 📝 Description
An advanced data migration system designed for PBBoard, enabling seamless data import from various global forum platforms (**vBulletin, XenForo, MyBB, phpBB**) into your PBBoard database.

---

## ⚙️ Technical Requirements
* **PBBoard Version:** 3.4 or higher.
* **PHP Version:** 8.0 or newer.
* **Database:** MySQL 4.1.3+ (MySQLi extension required).
* **Browser:** A modern web browser for optimal jQuery/AJAX processing.

---

## 🌍 Supported Platforms
| Platform | Supported Versions |
| :--- | :--- |
| **vBulletin** | 3.x, 4.x, 5.x |
| **XenForo** | 2.x |
| **phpBB** | 3.x |
| **MyBB** | 1.x |

---

## 🛠️ Installation Instructions

### A. File Upload
1. Upload the contents of the `Upload` folder to your PBBoard root directory on the server.

### B. Addon Import
1. Log in to your **Admin Control Panel (ACP)**.
2. Navigate to: `Addons` -> `Import Addons`.
3. Import the `PBBI-PBBoard-Import-1.0.xml` file.
---

## 🚀 Migration Steps (Usage)
1. In the Admin Control Panel (ACP), navigate to the new **"Import System | نظام الاستيراد >> Import Data | استيراد البيانات"** section.
2. Select the **Source Forum** type.
3. Enter the source forum database credentials (**Host, DB Name, User, Password**).
4. Provide the **Server Absolute Path** for the source forum directory (critical for migrating attachments and avatars).
5. Select the data tables you wish to import (Members, Threads, etc.).
6. Set the **Batch Processing Limit**:
   - **Shared Hosting:** 100 - 500
   - **Dedicated/VPS:** 1000 - 5000
7. Click **Continue** and monitor the real-time progress bar.

---

## 🧹 Post-Import Optimization
To ensure data integrity after migration, perform the following:
* Go to: `Admin Control Panel -> Maintenance -> Update Counters`.
* Execute: **Update all cache forums**.
* Execute: **Rebuild the permissions of groups for all sections & forums**.
---

## ⚠️ Important Notes
* ❗ **Backup:** Always perform a full database backup before starting the import process.
* 📂 **Permissions:** Ensure folder permissions (**CHMOD**) are correctly set for attachment migration.
* 🔑 **Passwords:** Hashing methods vary by platform. If a user cannot log in, advise them to use the **"Forgot Password"** feature.

---

## 👨‍💻 Credits
* **Developed by:** [SULAIMAN DAWOOD]
* **Support:** Dedicated to the [PBBoard Community](https://pbboard.info).
* **Release Date:** 01/01/2026

---
© 2026 PBBoard Importer Project.
