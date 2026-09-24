EDUS - Official User Manual 1.0     :root {         --primary: #4f46e5;         --sidebar-bg: #1e1e2f;         --text-main: #333;         --text-muted: #666;         --bg-light: #f9fafb;     }     body {         font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;         line-height: 1.6;         color: var(--text-main);         margin: 0;         display: block; /\* Changed from flex to block for better mobile control \*/         background-color: var(--bg-light);     }     /\* --- Hamburger Button --- \*/     .menu-toggle {         display: none;         position: fixed;         top: 15px;         left: 15px;         z-index: 1000;         background: var(--primary);         color: #fff;         border: none;         padding: 10px 15px;         border-radius: 5px;         cursor: pointer;         font-size: 1rem;         box-shadow: 0 4px 6px rgba(0,0,0,0.1);         transition: background 0.3s;     }     .menu-toggle:hover {         background: #3730a3;     }     /\* --- Sidebar --- \*/     .sidebar {         width: 300px;         background: var(--sidebar-bg);         color: #fff;         padding: 30px 20px;         height: 100vh;         position: fixed;         top: 0;         left: 0;         overflow-y: auto;         box-sizing: border-box;         z-index: 999;         transition: transform 0.3s ease-in-out; /\* Smooth sliding animation \*/     }     .sidebar h2 {         font-size: 1.4rem;         margin-bottom: 30px;         color: #fff;         border-bottom: 2px solid #333;         padding-bottom: 15px;         margin-top: 20px; /\* Space for the close button on mobile \*/     }     .sidebar ul {         list-style: none;         padding: 0;         margin: 0;     }     .sidebar li {         margin-bottom: 12px;     }     .sidebar a {         color: #cbd5e1;         text-decoration: none;         font-size: 0.95rem;         display: block;         transition: color 0.2s;     }     .sidebar a:hover {         color: var(--primary);     }     /\* --- Dark Overlay for Mobile --- \*/     .sidebar-overlay {         display: none;         position: fixed;         top: 0;         left: 0;         right: 0;         bottom: 0;         background: rgba(0,0,0,0.5);         z-index: 998;         opacity: 0;         transition: opacity 0.3s ease;     }     /\* --- Main Content --- \*/     .content {         margin-left: 300px;         padding: 50px 80px;         max-width: 1050px;         background: #fff;         min-height: 100vh;         box-sizing: border-box;         box-shadow: -5px 0 15px rgba(0,0,0,0.05);         transition: margin-left 0.3s ease;     }         .manual-screenshot {         display: block;         max-width: 100%;         height: auto;         border: 1px solid #e2e8f0;         border-radius: 8px;         margin: 25px 0;         box-shadow: 0 4px 6px rgba(0,0,0,0.05);     }     h1 { color: var(--primary); font-size: 2.5rem; margin-bottom: 10px; }     h2 { color: #1e293b; margin-top: 60px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }     h3 { color: #334155; margin-top: 35px; font-size: 1.3rem; }     p { margin-bottom: 15px; color: #475569; }     ul, ol { color: #475569; margin-bottom: 20px; }     li { margin-bottom: 8px; }     .image-placeholder {         background: #f1f5f9;         border: 2px dashed #cbd5e1;         padding: 40px 20px;         text-align: center;         color: #64748b;         margin: 25px 0;         border-radius: 8px;         font-weight: 500;         font-size: 0.9rem;     }     .note {         background: #fffbeb;         border-left: 5px solid #f59e0b;         padding: 20px;         margin: 30px 0;         border-radius: 0 8px 8px 0;     }     .note strong { color: #b45309; }     /\* --- MOBILE RESPONSIVENESS --- \*/     @media (max-width: 900px) {         .content {             padding: 40px 40px;         }     }     @media (max-width: 768px) {         /\* Show the hamburger menu \*/         .menu-toggle {             display: block;         }                 /\* Hide sidebar completely off-screen by default \*/         .sidebar {             transform: translateX(-100%);         }                 /\* Class added by JavaScript to slide sidebar in \*/         .sidebar.open {             transform: translateX(0);         }         /\* Class added by JavaScript to show overlay \*/         .sidebar-overlay.active {             display: block;             opacity: 1;         }         /\* Remove left margin on content so it takes full width \*/         .content {             margin-left: 0;             padding: 70px 20px 40px 20px; /\* Added extra top padding to avoid hiding content under the hamburger button \*/         }         h1 { font-size: 2rem; }     }     ☰ Menu
EDUS User Manual
----------------
*   [1\. Getting Started](#section-1)
*   [2\. Student Management](#section-2)
*   [3\. Admissions & Initial Billing](#section-3)
*   [4\. The School Store & POS System](#section-4)
*   [5\. Payment History & Bank Verification](#section-5)
*   [6\. Student Directory & Management](#section-6)
*   [7\. Expenses & Financial Health](#section-7)
*   [8\. System Administration](#section-8)
EDUS User Manual 1.0
====================
1\. Getting Started
-------------------
### 1.1 Welcome to EDUS
Welcome to EDUS, your complete platform for modern school finance management. EDUS is designed to help your institution transition from stressful manual record-keeping to secure, automated, and strategic financial management.
Whether you are tracking daily fee collections, managing termly expenses, or reviewing the overall financial health of your school, EDUS simplifies the process. Because EDUS uses an isolated database architecture, your school's financial data is completely private, secure, and accessible only to your authorized staff.
### 1.2 Logging In
![Login Screen](/images/manual/screencapture-demo-edus-ng-login-2026-04-21-13_46_33.png)
To access your school's dedicated EDUS portal:
1.  Open your preferred web browser (Google Chrome, Mozilla Firefox, or Microsoft Edge are recommended).
2.  Enter the unique EDUS web address provided to your school during registration.
3.  On the login screen, enter your **Email Address** and your **Password**.
4.  Click **Log In**.
### 1.2 The First-Time Setup Wizard
When you log in for the very first time, EDUS will automatically launch a guided Setup Wizard. This ensures your portal is customized to your specific institution before you begin operations.
**Step 1: School Identity**
![School Identity](/images/manual/screencapture-demo-edus-ng-onboarding-identity-2026-04-21-13_47_24.png)
This step builds your brand within the software. The details here will appear on your official invoices and receipts.
*   Enter your School/College Name and Motto.
*   Select your Institution Type (Secondary, Primary/Nursery, or College/Poly).
*   Choose your Brand Color to customize the theme of your portal.
*   Input your Official Email, Phone Number, and Physical Address.
*   Upload your School Logo, then click Next Step.
**Step 2: Build Your Class Structure**
![Class Structure](/images/manual/screencapture-demo-edus-ng-onboarding-structure-2026-04-21-13_48_10.png)
EDUS adapts to your specific school levels.
*   Check the boxes for all the class levels that exist in your school (e.g., JSS 1 through SS 3).
*   If your classes have Arms or Sub-divisions (e.g., JSS 1A, JSS 1B), enter those letters or names separated by commas (e.g., A, B, C).
*   Click Generate Classes.
**Step 3: Set Your Academic Calendar**
![Academic Calendar](/images/manual/screencapture-demo-edus-ng-onboarding-calendar-2026-04-21-13_48_31.png)
Define the active period for the portal.
*   Enter the Current Academic Session (e.g., 2026/2027) and the Current Term/Semester (e.g., First Term).
*   Select your official Resumption Date and Vacation Date using the calendar icons.
*   Click Set Calendar & Continue.
**Step 4: Configure Fee Structure**
![Configure Fee Structure](/images/manual/screencapture-demo-edus-ng-onboarding-fees-items-2026-04-21-13_49_06.png)
Here, you will define what you charge for.
*   Check the Active box next to any standard fees that apply to your school (Tuition, PTA Levy, etc.).
*   Set the Frequency (e.g., Every Term, Once per Session) and the Target audience (All Students, New Intake Only).
*   Use the + Add Custom Fee Item button if you have unique charges not listed.
*   Click Save Configuration & Set Prices.
**Step 5: Set Fee Prices**
![Set Fee Prices](/images/manual/screencapture-demo-edus-ng-onboarding-fees-prices-2026-04-21-13_49_34.png)
Now, you will assign specific monetary values to the fees you just activated.
*   The grid is divided into Part A (Returning Student Fees) and Part B (New Intake Fees).
*   Simply type the amount for each fee under the corresponding class level (e.g., JSS 1, SS 1).
*   Once your matrix is filled out, click Finish & Initialize Dashboard.
### 1.3 Setup Complete: Your Master Fee Schedule
![Setup Complete Master Fee Schedule](/images/manual/screencapture-demo-edus-ng-onboarding-summary-2026-04-21-13_49_59.png)
Congratulations! Your portal is now live. Upon finishing the wizard, you will see your Setup Complete! screen, displaying your Master Fee Schedule. This acts as your financial blueprint for the term.
From this screen, notice the Main Navigation Menu on the left side. This menu is your control center for accessing Students, Finance operations, Store Sales, and System Settings.
### 1.4 Understanding Your Navigation Menu
![Left Navigation Menu](/images/manual/screencapture-demo-edus-ng-academic-2026-04-21-14_30_47.png)
The left-hand side menu is your permanent control center. It is divided into three main operational categories: Academic, Finance, and System.
*   **ACADEMIC:** This section handles all student records and the school calendar (Sessions & Terms, School Calendar, New Admission, Bulk Import, Students, Promote Students, Classes).
*   **FINANCE:** This section is the core of your EDUS portal, handling all money flowing in and out of the school (Fee Schedule, Academic Fees, Store Sales / Store Inventory / Store POS, Payment History, Scholarships, Expenses, Profit & Loss).
*   **SYSTEM:** This section manages your software account and administrative preferences (My Subscription, My Wallet, Settings).
2\. Student Management: Migrating Your Records
----------------------------------------------
Now that your School Identity, Classes, and Fees are configured, it is time to populate EDUS with your student body. After clicking the "Confirm Fees & Import Students" button at the end of the Setup Wizard, you will arrive at the Quick Student Migration page.
### 2.1 The Quick Student Migration Page
![Quick Student Migration Webpage](/images/manual/screencapture-demo-edus-ng-quick-enroll-2026-04-21-13_54_47.png)
This page offers two ways to bring existing students into your new system. Key elements include:
*   Download CSV Template Button: The critical first step for bulk data migration.
*   Select Term Dropdown: You must tell the system which term these students belong to.
*   Generate Bills? Checkbox: If checked, EDUS will automatically generate invoices for every student based on the prices you set in the Onboarding Wizard. \[We recommend keeping this checked for all returning students\].
*   Upload and Process Button: Submits your completed spreadsheet.
### 2.2 Deep Dive: Option A - Bulk CSV Upload (Recommended)
This method is recommended for schools with over 20 students. It requires you to accurately fill out the EDUS Excel Template.
**Step 1: Download and Understand the Template**
![Excel CSV Template Format](/images/manual/Screenshot 2026-04-21 135749.png)
Click the \[Download CSV Template\] button in Option A. Open this file using Excel or Google Sheets.
CRITICAL RULES FOR THE TEMPLATE:
1.  Do Not Alter Headers: The first row containing the column names (First Name, Middle Name, etc.) must remain unchanged.
2.  One Row Per Student: Each line in the spreadsheet represents one student.
3.  Accuracy Matters: Ensure names are spelled correctly and data is clean before uploading.
**Step 2: Filling Data into Key Columns**
*   First Name / Last Name: (Required).
*   Middle Name: (Optional).
*   Gender: Enter exactly M for Male or F for Female.
*   DOB (Date of Birth): **\[Crucial Format Alert\]** You must use the format YYYY-MM-DD (e.g., 2015-01-01). Do not use Excel's default date formatting like 1/1/2015 as it will fail validation.
*   Admission Number: Enter the unique number. If you leave this blank, EDUS will automatically generate a sequential ID.
*   Class Name: **\[Critical Link\]** This must match your system configuration exactly. For example, if you created "JSS 1" with Arms "A, B, C", you cannot enter "JSS1 Gold" here. It must be "JSS 1A", "JSS 1B", or simply "JSS 1".
*   Parent Name / Parent Phone: (Required). These fields link the student to a responsible guardian in the system.
**Step 3: Validating and Processing the Upload**
1.  Return to the Quick Student Migration page.
2.  Select the Term you are importing students into.
3.  Ensure "Generate Bills?" is checked if you want them immediately invoiced.
4.  Click "Choose File" and select your completed CSV.
5.  Click \[Upload & Process\].
3\. Admissions & Initial Billing
--------------------------------
Unlike the Bulk Import tool, the New Admission module is used for everyday enrollments. EDUS utilizes a "Parent-First" architecture. This means the system links students to a central Guardian profile, allowing parents with multiple children in the school to manage all their wards from a single account.
### 3.1 Step 1: Parent Identification
![Parent Identification / Registration](/images/manual/screencapture-gobarau-localhost-8082-admission-2026-04-21-15_02_58.png)
Before entering a student's details, you must identify their guardian.
1.  Navigate to Academic > New Admission.
2.  Under Existing Parent?, type the parent's phone number to search the database.
**⚠️ CRITICAL NOTE REGARDING PHONE NUMBERS:** Do NOT include the leading zero when entering phone numbers (e.g., enter 8036249925 instead of 08036249925). The system's automated SMS notification infrastructure requires this exact format to successfully deliver payment receipts and alerts to parents.
3.  If the parent already exists in the system, their name will appear in a green box. Click Continue with this Parent.
4.  If they do not exist, skip the search box and fill out the New Parent Registration form on the right (Name, Phone, Email, etc.), then click Create Profile & Next Step.
### 3.2 Step 2: Student Registration & Siblings
![Student Registration Form](/images/manual/screencapture-gobarau-localhost-8082-admission-register-1-2026-04-21-15_07_41.png)
1.  Fill in the student's Basic Identity (Name, DOB, Gender).
2.  Select their Academic Placement (the Class they are being admitted into).
3.  Complete the Origin and Medical Profile sections (crucial for school clinic emergencies).
4.  Click Complete Admission.
![Admission Complete Success Screen](/images/manual/screencapture-gobarau-localhost-8082-admission-register-3-2026-04-21-15_14_26.png)
Upon successful admission, you will see a success screen. If the parent is enrolling more than one child today, simply click **\+ Register Sibling (Same Parent)** to immediately loop back to the student form without needing to re-enter the parent's details!
### 3.3 Step 3: Generating the Student's Invoice
![Generating Student Invoice on Profile](/images/manual/screencapture-gobarau-localhost-8082-students-10-profile-2026-04-21-15_26_47.png)
Newly admitted students do not have invoices generated automatically upon filling out the form. The Bursar must trigger this to ensure the billing is accurate.
1.  Navigate to the student's newly created profile.
2.  At the top of the profile, you will notice a warning: **FIRST TERM BILLING: ⚠️ Not Generated**.
3.  Under the Financial Overview, the Total Debt will show N0.00.
4.  Click the yellow **⚙️ GENERATE NOW ->** button. The system will now calculate their precise fees based on their specific class and new intake status.
### 3.4 Step 4: The Payment Terminal
![Payment Terminal](/images/manual/screencapture-gobarau-localhost-8082-finance-payment-terminal-10-2026-04-21-15_29_26.png)
Clicking "Generate Now" immediately redirects you to the Payment Terminal for that specific student's newly generated invoice. From this screen, the Bursar has three options:
*   **Record Cash Immediately:** If the parent is standing in the office with cash, type the amount handed to you into the "Amount to Pay" box and click Confirm Cash Payment. The system will adjust the balance and log the transaction.
*   **Log a Bank Transfer:** Click Generate Payment Slip to record an offline bank deposit.
*   **Wait for Parent Portal Payment:** If the parent intends to pay later from home, simply navigate away from this screen. The invoice is now active and waiting on the Parent's Dashboard.
**💡 A Note on Our Payment Philosophy: Why Manual Verification?**
You might wonder why EDUS prioritizes offline bank transfers and manual slip uploads rather than instant online card payments. The answer is simple: **We protect your school's revenue.**
Automated online payment processors deduct a percentage-based service fee from every single transaction. For most educational institutions, losing a portion of every student's tuition to third-party processing charges is simply not sustainable. By utilizing our secure "Upload Slip & Verify" architecture, your school receives 100% of the owed fees directly into your designated bank account without any external deductions. Meanwhile, parents still enjoy a modern, digital experience by submitting their proof of payment right from their phones.
_Future Upgrade Notice: We recognize that as schools grow, some may prefer to prioritize instant processing over transaction fees. In an upcoming EDUS feature release, we will introduce optional, automated payment gateway integrations for institutions that wish to eliminate manual Bursar verification entirely._
### 3.5 Important: Parent Portal Login Credentials
Parents have their own dedicated login to view their children's invoices and academic progress. It is the Admin's responsibility to inform parents of their default login credentials, which differ based on how the student was enrolled into the EDUS system:
*   **For Parents Registered Manually (Via the Admissions Form):**
Username: The specific Email Address provided during the parent registration step.
Default Password: _parent_
*   **For Parents Registered via Bulk CSV Upload:**
Username: Their Phone Number combined with the .edus.ng suffix (e.g., 8031234567.edus.ng).
Default Password: _12345678_
_Note: Parents are strongly encouraged to log in and change these default passwords immediately for security purposes._
### 3.6 The Parent Portal Experience
The EDUS Parent Portal is designed to give guardians full transparency over their children's financial standing with the school, reducing the number of phone calls and physical visits to the Bursar's office.
**3.6.1 Parent Dashboard Overview**
![Parent Portal Dashboard](/images/manual/screencapture-gobarau-localhost-8082-parent-dashboard-2026-04-21-15_53_27.png)
*   **Student Cards:** Parents will see a summary card for each of their children enrolled in the school. The card displays the student's name, Admission Number, and current Financial Status (e.g., N2,900.00 Outstanding).
*   **Payment Status:** If a parent recently uploaded a bank transfer slip (or if the Bursar logged an offline transfer), the status will show a yellow "Verifying..." tag until the Bursar officially confirms the funds have settled in the school's bank account.
**3.6.2 Reviewing Financial History**
![Parent Portal Financial History](/images/manual/screencapture-gobarau-localhost-8082-parent-student-10-finance-2026-04-21-15_54_30.png)
By clicking the History button on a student's card, the parent can dive deeper into that specific child's records. This page is divided into two sections:
1.  **Invoices (Bills):** A list of all termly bills generated for the student. It shows the Term, the Status (e.g., Unpaid, Partial, Paid), and the Total Amount Due.
2.  **Payment Receipts:** A ledger of every payment attempt made. In the example above, a N2,900 Transfer is marked as PENDING, meaning the parent has submitted proof of payment and is waiting for the school to approve it. Parents can download their official receipts from this section once approved.
**3.6.3 Viewing the Itemized Invoice**
![Parent Portal Invoice Details](/images/manual/screencapture-gobarau-localhost-8082-finance-invoice-12-2026-04-21-15_54_51.png)
*   **Fee Breakdown:** Details every charge applied to that student based on their class level and whether they are a new or returning student (e.g., Tuition, PTA Levy, Uniforms).
*   **Transaction History:** Shows any pending or successful payments applied against this specific invoice.
*   **Generate Payment Slip:** If the parent intends to go to a physical bank branch to make a deposit, they can click this button to generate a slip containing the school's bank details and their specific Invoice Reference Number to use as the payment description.
4\. The School Store & POS System
---------------------------------
EDUS features a built-in Point of Sale (POS) and inventory management system. This allows your school to seamlessly handle the sale of physical items like textbooks, uniforms, and stationery without needing separate accounting software.
### 4.1 Managing Store Inventory
![Store Inventory List](/images/manual/screencapture-gobarau-localhost-8082-store-products-2026-04-21-16_28_38.png)
Before you can sell anything, you must stock your digital shelves. Navigate to Finance > Store Inventory.
*   **Add Single Item:** Use the panel on the left to manually enter a new product. You can specify the Item Name, Category (e.g., Books, Stationery), Price, and the initial Stock quantity.
*   **Class Assignment:** You can assign items to specific classes (e.g., "Math Book 2" for JSS 2) or leave it as a "General Item" for everyone.
**Bulk Uploading Inventory:**
![Store Inventory Bulk Upload CSV](/images/manual/screencapture-gobarau-localhost-8082-store-products-2026-04-21-16_29_10.png)
If you have a large list of items, click the green Bulk import CSV button at the top right. A green guide box will appear, showing you the exact columns your Excel file needs: Name | Category | Price | Stock | ClassID.
**⚠️ CRITICAL NOTE ON CLASS IDs:** When filling out the ClassID column in your CSV, do not include class arms (e.g., Do not write "JSS 1A"). Arms are used strictly for population control in classrooms. For inventory, simply use the base class level with a space, such as **JSS 1** or **SS 2**.
### 4.2 The Point of Sale (POS) Terminal
![POS Terminal Student Search](/images/manual/screencapture-gobarau-localhost-8082-store-sales-search-2026-04-21-16_23_04.png)
When a student or parent comes to the administrative office to purchase an item, navigate to Finance > Store POS. The POS system offers two distinct workflows to give the Bursar maximum flexibility:
**Option A: Selling to an Enrolled Student**
1.  Use the search bar to find the student by Name or Admission Number.
2.  Select the student to open the POS Terminal linked to their profile.
3.  Add the items they wish to purchase to the cart.
4.  **Benefit:** This records the sale directly to the student's official profile. The Bursar can process the payment immediately, or the bill can be left unpaid so the parent can view it and pay it later via the Parent Portal.
**Option B: Walk-In Sale (Instant Cash)**
![POS Terminal Walk-in Sale](/images/manual/screencapture-gobarau-localhost-8082-store-sales-cart-2026-04-21-16_23_31.png)
1.  Click the green Walk-In Sale button.
2.  Enter the Customer's Name manually (e.g., Mr. Ibrahim, Parent, or Guest).
3.  Select the products from the dropdown to add them to the cart.
4.  Click the green CASH SALE (WALK-IN) button to instantly log the payment.
![POS Instant Cash Receipt](/images/manual/screencapture-gobarau-localhost-8082-finance-payment-receipt-walk-in-13-2026-04-21-16_25_48.png)
The system will immediately generate a Cash Receipt that can be printed and handed to the customer on the spot.
### 4.3 Understanding Financial Ledgers: Academic vs. Store
To keep the school's accounting clean and accurate, EDUS automatically separates mandatory school fees from physical store purchases.
**The Academic Fees Ledger (Finance > Academic Fees)**
![Academic Fees Ledger](/images/manual/screencapture-gobarau-localhost-8082-finance-invoice-academic-2026-04-21-16_32_40.png)
*   This ledger tracks purely academic and statutory bills (Tuition, PTA, Exam Fees).
*   It provides a clear view of which students have cleared their termly obligations and who still has an outstanding balance.
**The Store Sales Ledger (Finance > Store Sales)**
![Store Sales Ledger](/images/manual/screencapture-gobarau-localhost-8082-finance-invoice-store-2026-04-21-16_26_33.png)
*   This ledger tracks every single POS transaction, separating Walk-In customers from enrolled students.
*   The Bursar and Sales Personnel can use this screen to monitor total shop revenue, manage unpaid store bills, and track overall retail performance.
5\. Payment History & Bank Verification
---------------------------------------
The Payment History module is your central hub for tracking all financial transactions related to students. Most importantly, it is where the Bursar officially confirms and clears the pending bank transfers uploaded by parents via their portal.
### 5.1 The Payment Terminal Search
![Payment Terminal Search Bar](/images/manual/screencapture-gobarau-localhost-8082-finance-payment-2026-04-21-17_51_31.png)
To look up any student's billing profile or payment record, navigate to Finance > Payment History.
*   **Searching:** Simply type the student's Name or their Admission Number (e.g., 2026/100) into the search bar and click Search.
*   **Selecting a Record:** The matching student will appear under the "Search Results." Clicking on their name will take you directly to their specific financial profile to view their invoices or log new cash payments.
### 5.2 Verifying Parent Bank Transfers
![Verify Bank Transfer Code Entry](/images/manual/screencapture-gobarau-localhost-8082-finance-payment-verify-2026-04-21-17_53_54.png)
When a parent uploads a proof of payment from their Parent Portal, it is marked as "Pending" until the school verifies that the money has actually arrived in the school's bank account. To verify a transfer:
1.  On the Payment Terminal screen, click the **Verify Bank Transfer** link located just below the search bar.
2.  You will be prompted to enter a code. Type in the Reference Number associated with the parent's payment slip (e.g., 260412).
3.  Click Search.
![Confirm Payment Received Verification](/images/manual/screencapture-gobarau-localhost-8082-finance-payment-verify-2026-04-21-17_56_45.png)
4.  **Confirming the Funds:** The system will pull up the exact details of that pending transaction, showing the Student's Name, the Amount (e.g., N2,900.00), and the Date Initiated.
5.  **Final Approval:** Once your accounting team checks the school's bank statement and confirms the alert, click the green Confirm Payment Received button.
_Note: The moment you click this button, the student's balance is automatically updated, and the parent's portal will switch from "Verifying..." to a finalized "Paid" status, allowing them to download their official receipt._
6\. Student Directory & Management
----------------------------------
The Students module is the central digital filing cabinet for every enrolled pupil. It allows administrators to quickly look up records, print official documents, and monitor individual financial health.
### 6.1 Navigating the Student Directory
![Main Student Directory List](/images/manual/screencapture-gobarau-localhost-8082-students-2026-04-21-21_22_33.png)
To access the main registry, navigate to Academic > Students on the left-hand menu. The directory is designed for speed and efficiency:
*   **Filtering & Searching:** Use the dropdowns at the top to filter students by their specific Class or their Finance Status (e.g., finding only students who owe money). You can also search directly by Name or Admission Number.
*   **At-a-Glance Status:** The "Finance Status" column immediately shows you if a student is Cleared (green) or if they have an outstanding debt (red tag showing the exact amount owed).
*   **Exporting Data:** The green Export to Excel button allows the admin to download the current filtered list for offline reporting.
*   **Quick Actions:** Every student row features direct action buttons: View Profile, Print Letter, and Finance.
### 6.2 Managing Individual Student Profiles
![Individual Student Profile View](/images/manual/screencapture-gobarau-localhost-8082-students-10-profile-2026-04-21-21_24_01.png)
Clicking View Profile from the directory opens the student's complete record. This page is the master record for the student:
*   **Top Controls:** You can click Edit Profile to correct any mistakes, click Photo to upload or update their passport picture, or click the green ID Card button to generate their printable school identity card.
*   **Data Panels:** Review their Personal & Academic Data, Guardian Details (including contact info), and critical Medical Profile alerts.
*   **Financial Overview:** This section gives a snapshot of their current debt. The Bursar can click the Pay Now button to jump straight to the Payment Terminal for this student.
### 6.3 Printing Official Documents
![Print Admission Letter](/images/manual/screencapture-gobarau-localhost-8082-admission-student-10-print-letter-2026-04-21-21_25_15.png)
EDUS automates the creation of formal school documents. By clicking Print Letter directly from the Student Directory, the system generates a formatted Provisional Admission Letter.
*   The letter is automatically populated with the school's logo, the parent's name, the student's admission number, and the designated class.
*   Simply click the blue Print A4 button at the top right to print the letter and hand it to the parent, or save it as a PDF.
### 6.4 Reviewing Individual Financial History
![Student Specific Financial Ledger](/images/manual/screencapture-gobarau-localhost-8082-students-9-finance-2026-04-21-21_25_40.png)
To investigate a specific student's payment history without wading through the entire school's ledger, click the Finance button on their directory row, or click Full History from their profile page.
*   **Comprehensive Ledger:** This screen displays every single invoice generated for the student across all terms and sessions. It details the Total billed, the Amount Paid, the remaining Balance, and the Status.
*   **Invoice Details:** Click View next to any invoice to see the itemized breakdown of the charges.
*   **Quick Payment Access:** If a parent is ready to pay an outstanding balance while you are reviewing this page, simply click the purple Open Payment Terminal button at the top right to instantly log the transaction.
### 6.5 End of Session: Student Promotion & Repetition
At the end of every academic year, administrators must update the system to reflect class advancements. EDUS makes this bulk process seamless through the Promotion module, ensuring students are billed correctly in the new session.
To begin the promotion process, navigate to Academic > Promote Students on the left-hand menu (or click the "Batch Promote" button from the Student Directory).
![Promote Students Module](/images/manual/screencapture-gobarau-localhost-8082-enrollment-promote-2026-04-21-21_26_26.png)
The Promotion interface is divided into two clear steps:
**Step 1: Moving From (Old Session)**
1.  Select the Source Session (the academic year that is currently ending).
2.  Select the Source Class you want to evaluate (e.g., JSS 1 A).
3.  Click the blue Load Students button. This will generate a roster of all students currently sitting in that class.
**Step 2: Moving To (New Session)**
1.  Select the Target Session (the upcoming academic year).
2.  Select the Target Class (Promoted) to dictate where the successful students are heading next (e.g., JSS 2 A).
3.  **Handling Repetitions:** Once the roster is loaded, check the boxes next to the names of the students who have passed. Pay attention to the critical system note: _Students NOT selected below will repeat the Source Class._ If a student failed and needs to repeat the year, simply leave their name unchecked.
4.  Process the promotion to instantly update the profiles and financial records for the entire cohort!
7\. Expenses & Financial Health
-------------------------------
While the Academic and Store ledgers track your school's income, EDUS also provides robust tools to track your daily operational expenditures. Combining these two elements gives the school proprietor a real-time view of the institution's true financial health.
### 7.1 Setting Up Expense Categories
![Expense Categories](/images/manual/screencapture-gobarau-localhost-8082-finance-expenses-categories-2026-04-21-21_29_28.png)
Before recording daily costs, it is best practice to organize how your school spends money. Navigate to Finance > Expenses and click the Manage Categories → link located at the top of the Record Expense panel.
*   Use the New Category form to create standard groupings for your school's outgoing funds.
*   Common examples include "Generator Fuel," "Staff Salaries," "Stationery & Supplies," or "Building Maintenance."
*   Click Save Category. These will now appear in your dropdown menu when logging daily expenses.
### 7.2 Recording Daily Expenses
![Expense Manager Dashboard](/images/manual/screencapture-gobarau-localhost-8082-finance-expenses-2026-04-21-21_29_01.png)
To log a routine school purchase or payment, navigate back to the main Finance > Expenses page. The Expense Manager makes recording outgoing cash fast and accurate:
1.  **Title / Description:** Enter a brief description of the purchase (e.g., "Purchase of Diesel for Main Generator").
2.  **Amount (N):** Enter the exact cost.
3.  **Date:** Select the day the expense occurred.
4.  **Category:** Choose the appropriate budget group from the dropdown you configured earlier.
5.  **Additional Notes:** (Optional) Add any necessary context, such as the name of the vendor or the staff member who made the purchase.
6.  Click Save Record.
Once saved, the transaction will appear in the "Recent Transactions" list, and the "Total Spent" metric at the top right of the screen will automatically update.
### 7.3 Generating Profit & Loss Reports
![Profit and Loss Visual Dashboard](/images/manual/screencapture-gobarau-localhost-8082-finance-report-profit-loss-2026-04-21-21_29_53.png)
The ultimate tool for the school proprietor is the Profit & Loss dashboard. This screen automatically calculates your net revenue without requiring any manual spreadsheet work. Navigate to Finance > Profit & Loss on the left-hand menu.
*   **Custom Date Filtering:** Use the calendar fields at the top to filter the report. You can review the financial performance of a specific week, a single term, or the entire academic session. Click Filter Report to update the data.
*   **Total Revenue:** This card automatically pulls all collected income and splits it between your Academic Fees (tuition, levies) and your Store Sales (books, uniforms), giving you clear insight into your revenue streams.
*   **Total Expenses:** This pulls the sum of everything logged in the Expense Manager during that timeframe.
*   **Net Profit / (Loss):** The system automatically subtracts your expenses from your revenue to display your exact profit.
*   **Cash Flow Visualization:** A clear, color-coded bar chart provides a visual percentage breakdown of how much of your revenue was retained as profit versus how much was consumed by operational expenses.
8\. System Administration
-------------------------
The System section on your left-hand navigation menu is restricted to the Proprietor or top-level Administrator. Here, you manage your EDUS software license, fund your communication wallet, and customize your school's core identity.
### 8.1 My Subscription
![Subscription Plans](/images/manual/screencapture-gobarau-localhost-8082-subscription-2026-04-21-21_38_33.png)
To keep your EDUS portal active and seamlessly manage your student data, you must maintain an active subscription. Navigate to System > My Subscription.
*   **Current Status:** The top banner displays your current active plan and its expiration date. If your trial or plan has expired, a red "EXPIRED" tag will alert you.
*   **Choosing a Plan:** EDUS offers flexible plans based on your school's size:
*   Starter: Best for small schools (Up to 100 Students).
*   Growth Plan: Best for mid-sized institutions (Up to 400 Students).
*   Scale Plan: Best for large schools needing unlimited student capacity.
*   **Included Credits:** Notice that paid plans come with bundled Wallet Credits, which you can use for automated SMS notifications!
*   **How to Upgrade:** Simply click the dark Switch to \[Plan Name\] button under your desired tier to extend your access for the term.
### 8.2 My Wallet (SMS & Communication Credits)
![Wallet and Credits Dashboard](/images/manual/screencapture-gobarau-localhost-8082-wallet-2026-04-21-21_39_02.png)
EDUS keeps parents informed through automated SMS alerts for things like payment receipts and new enrollments. To send these messages, your school needs a funded communication wallet. Navigate to System > My Wallet.
*   **Checking Your Balance:** The large purple card displays your available balance in Naira.
*   **Quick Top Up:** To add funds, enter an amount in the input box (Minimum: N1,000) and click Add Funds. Just like parent payments, you will be asked to upload your proof of payment for the system admin to verify and credit your wallet.
*   **Transaction History:** The bottom panel keeps a permanent ledger of all your wallet top-ups and credit deductions, ensuring complete transparency over your communication spending.
### 8.3 General Settings
![General System Settings](/images/manual/screencapture-gobarau-localhost-8082-settings-general-2026-04-21-21_39_33.png)
The Settings module is where you configure exactly how your school appears to parents and on official printed documents. Navigate to System > Settings.
This page controls several critical functions:
1.  **Contact Details & Identity:** Update your School Name, Official Email, Physical Address, Motto, and Brand Color.
_Pro Tip: Use the Live Preview panel on the right side of the screen to see exactly how your logo and brand color will look on official documents!_
2.  **Banking Details (Crucial for Payments):** The Bank Name, Account Number, and Account Name you enter here are extremely important. This is the exact bank account that will be printed on the "Payment Slips" generated for parents in their portal. Ensure this is your official school receiving account.
3.  **Notification Settings (SMS Toggles):** Here, you can control what automated messages are sent to parents. You can check or uncheck the boxes to enable/disable:
*   New Enrollment Alerts (Sent when a student is admitted).
*   Fee Payment Receipts (Sent when a Bursar confirms a payment).
*   Calendar & Event Reminders.
_Note: The cost per SMS unit (e.g., N12/unit) is displayed next to each option. These funds are deducted from your "My Wallet" balance._
4.  Click the purple **Save School Settings** button at the bottom to instantly apply your changes across the entire EDUS platform.
document.addEventListener('DOMContentLoaded', function() {         const menuToggle = document.getElementById('menuToggle');         const sidebar = document.getElementById('sidebar');         const overlay = document.getElementById('sidebarOverlay');         const sidebarLinks = sidebar.querySelectorAll('a');         // Function to toggle the menu open/closed         function toggleMenu() {             sidebar.classList.toggle('open');             overlay.classList.toggle('active');         }         // Open/Close menu when the hamburger button is clicked         menuToggle.addEventListener('click', toggleMenu);         // Close menu when the dark overlay is clicked         overlay.addEventListener('click', toggleMenu);         // Close menu when a link inside the sidebar is clicked         sidebarLinks.forEach(link => {             link.addEventListener('click', () => {                 // Only close it automatically if we are on a mobile screen                 if (window.innerWidth <= 768) {                     toggleMenu();                 }             });         });     });