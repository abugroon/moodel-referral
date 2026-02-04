# Local Referral Plugin for Moodle

A powerful referral and commission tracking system for Moodle. This
plugin allows administrators to create marketers, generate referral
links, and automatically track commissions when users enroll in courses
using referral codes.

------------------------------------------------------------------------

## 🚀 Features

-   Create and manage marketers
-   Generate unique referral links
-   Track referred users
-   Automatically calculate commissions
-   Approve or mark commissions as paid
-   Status filtering (Pending / Approved / Paid)
-   Copy referral link button
-   Fully compatible with Moodle 4+

------------------------------------------------------------------------

# 📥 Download & Installation

You can install this plugin in two ways:

## ✅ Method 1: Download ZIP from GitHub

1.  Click the green **Code** button.

2.  Click **Download ZIP**.

3.  Extract the ZIP file.

4.  Rename the folder to:

    referral

5.  Upload it to:

    /local/

Final path must be:

yourmoodlesite/local/referral

6.  Login as Admin.

7.  Go to:

    Site administration → Notifications

8.  Complete installation.

------------------------------------------------------------------------

## ✅ Method 2: Install via Git

Navigate to your Moodle local folder:

cd /path/to/moodle/local

Then run:

git clone https://github.com/YOUR-USERNAME/local_referral.git referral

Then visit:

Site administration → Notifications

------------------------------------------------------------------------

# 📁 Plugin Location

This is a Local Plugin and must be placed inside:

/local/referral

------------------------------------------------------------------------

# 🔗 How It Works

1.  Admin creates a marketer.

2.  Plugin generates:

    https://yourmoodlesite.com/?ref=MARKETERCODE

3.  When a user registers or enrolls using that link:

    -   Referral is recorded
    -   Commission is created

4.  Admin can approve or mark commission as paid.

------------------------------------------------------------------------

# 🗂 Database Tables

-   local_ref_marketers
-   local_ref_users
-   local_ref_commissions

------------------------------------------------------------------------

# 🧾 Commission Status

Pending = 0\
Approved = 1\
Paid = 2

------------------------------------------------------------------------

# ⚙️ Requirements

-   Moodle 4.0+
-   PHP 8.0+
-   Bootstrap 5 compatible theme

------------------------------------------------------------------------

# 🔒 Security

-   sesskey validation
-   moodle/site:config capability required
-   Moodle DB API used

------------------------------------------------------------------------

# 📄 License

GNU GPL v3 or later

------------------------------------------------------------------------

# 👨‍💻 Author

Moawia Ahmed\
Senior Full-Stack Software Consultant
