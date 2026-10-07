# Astona Staff Guide

For owners and staff of the coaching center. You do not need a developer to do anything in this guide. Button and menu names are written in **bold** exactly as they appear on screen.

## Read this first: what is not live yet

Until the owner confirms in **Astona > Settings > System status** that the payment gateway says **bkash** and the SMS driver is a real one (not `fake`), the website runs with **test versions** of two services:

- **Online payment (bKash)**: the payment page is a test page. Real money cannot be taken online yet.
- **SMS**: text messages (login passwords, login codes, critical notices, "application not approved" messages, receipts) are not sent to real phones yet.

The online application form also needs SMS: the applicant must confirm their own mobile number with a code sent by SMS before they can submit. With the test SMS version nobody receives that code.

**Do not promise students or parents online payment or SMS** until the owner confirms both are switched on. Until then, take admissions and payments at the office and tell students to contact you.

Owner check: **Astona > Settings**, section **System status**. If **Payment gateway** or **SMS primary driver** says `fake`, the test versions are still in use.

## 1. Signing in

1. Go to your site address followed by `/admin/login/`.
2. Enter your username and password. Owners and staff also type the **6-digit code from the authenticator app** on their phone (or one recovery code) in the **Authenticator code** box.
3. You land on the **Astona dashboard**. Everything for daily work is under **Astona** in the left menu.

**First time (owners and staff).** After your first password sign-in the system takes you to **Two-factor authentication**. Install an authenticator app on your phone (Google Authenticator, Microsoft Authenticator or Authy), add the account by typing the **Key** shown (or tapping **Open in authenticator app** on the same phone), type the code the app shows and click **Turn on two-factor**. You are then shown **8 recovery codes once**: write them down and keep them somewhere safe. Each works one time if you lose your phone. If you are locked out and have no recovery code, ask the technical contact to reset your two-factor.

You are signed out automatically after **60 minutes** without activity. Before downloading a CSV export the system asks you to type your password again.

Students cannot use this page. They sign in at `/student/login/`.

### What each role can do

| Area | Owner | Staff | Instructor |
|---|---|---|---|
| Dashboard | Yes | Yes (cards you have access to) | Welcome message only |
| Applications (view, reject) | Yes | Yes | No |
| Reveal ID number | Yes | No | No |
| Students (roster, resend credentials, deactivate) | Yes | Yes | No |
| Payments (view, export) | Yes | Yes | No |
| Reconcile now | Yes | No | No |
| Course editor, Course content, Live classes, Notices | Yes | Yes | No |
| Inquiries, Media | Yes | Yes | No |
| Blog, Gallery, Results, Courses, Faculty, Branches | Yes | Yes | No |
| Audit log, SMS log, Settings | Yes | No | No |

If a menu item is missing, your role does not include it. Ask the owner.

## 2. The dashboard

**Astona > Dashboard** shows these cards (you only see the ones your role allows):

- **Pending applications**: applications waiting for payment. Click the number to open the list.
- **Applications today**, with the last 7 days underneath.
- **Revenue today**, with the last 30 days and the total underneath.
- **Stuck payments**: payments that need a person to look at them. Click the number to open them.
- **New inquiries**: messages from the Contact form not yet handled.
- **Active enrollments**: students currently enrolled.

The owner also sees **Recent activity** (the latest staff actions).

## 3. Courses, batches, faculty and branches

### Edit batches, modules and lessons on one screen

**Astona > Course editor** shows one course with all its batches, modules and lessons together. Pick the course, then:

- Click a batch name to open it. Change its fields, tick **Applications open**, **Waitlist when full** or **Allow two-part payment**, and add **Modules** and **Lessons** under it (use the arrow buttons to reorder, **Move to...** to put a lesson in another module).
- The bar next to each batch shows **how many seats are taken** (yellow from 80%, red when full). Capacity cannot be set below the seats already taken, and a batch that has applications cannot be deleted: set its status to **Closed** instead.
- Nothing is saved until you click **Save all** (top bar). **Unsaved changes** is shown while you have edits, and the browser warns you before you leave. If a colleague saved the same course first you will see "Someone else changed this course": click **Reload now** and redo your edit.
- Lesson PDFs are still uploaded under **Course content**; a lesson keeps its PDF when you move it.

### Add a course with batches

1. Go to **Courses > Add New**.
2. Enter the title, description and excerpt, set a **Featured image**, and pick a **Course Category**.
3. In the **Course details** box fill in:
   - **Duration** (for example "6 months").
   - **Syllabus**: one topic per line.
   - **Instructors**: choose from Faculty (hold Ctrl or Cmd to pick several).
4. In the **Batches (schedule, price, capacity)** box, fill one row per batch:
   - **Name**
   - **Mode**: Physical, Online or Hybrid
   - **Capacity**: number of seats
   - **Price (BDT)**
   - **Start** and **End** dates (End is optional)
   - **Schedule**, for example "Sat, Mon, Wed · 6:00–7:30 PM"
   - **Status**: Draft, Open, Closed or Completed
   - **Applications open** (tick to accept applications)
5. Click **Publish** (or **Update** if the course already exists).

To remove a batch, clear its **Name** and save. Two blank rows are always provided for new batches.

Result: the course appears on the public site. Batches with Status **Draft** are hidden from the public.

**Seats taken** is shown for each batch but you cannot edit it. It goes up only when a payment is completed.

### What Open, Filling Fast and Closed mean

The chip on the website is worked out automatically. You never type it.

- **Closed**: the batch Status is not Open, **or** **Applications open** is unticked, **or** capacity is 0, **or** all seats are taken.
- **Filling Fast**: the batch is open and 80% or more of the seats are taken.
- **Open**: everything else.

A course shows its best batch: if any batch is Open the course shows Open.

### Add faculty

1. Go to **Faculty > Add New**.
2. Enter the name (title), bio and photo.
3. In **Faculty details** fill **Subject** and **Credentials**.
4. Click **Publish**. Then the person can be chosen under **Instructors** on a course.

### Add a branch

1. Go to **Branches > Add New**.
2. Enter the branch name as the title.
3. In **Branch details** fill **Address**, **Phone**, **Opening hours** and **Map embed URL**.
4. Click **Publish**.

The map link must be a Google Maps embed link (starts with `https://www.google.com/maps/embed?`) or an OpenStreetMap embed link (starts with `https://www.openstreetmap.org/export/embed.html?`). Any other link is discarded.

## 4. Course content (modules and lessons)

Go to **Astona > Course content**. Content belongs to a batch.

1. In **Course and batch**, choose a batch and click **Open**.
2. Under **Add module**, type a **Module title** and click **Add module**.
3. In the module, under **Add lesson**, enter the **Title** and **Date and time**. To attach notes, choose a file under **PDF notes (max 10 MB)**. Click **Add lesson**.
4. To change the order, use **Move up** or **Move down** on a module or lesson.
5. To change something, click **Edit** (lesson) or **Rename** (module).
6. To replace a PDF, edit the lesson and choose a file under **Replace PDF**. To remove it, tick the **Remove ...** box and save.

Only PDF files up to 10 MB are accepted. **Delete module** removes the module and all its lessons. **Delete** removes a lesson and its PDF. Both ask you to confirm. Times are shown in the site timezone.

Result: enrolled students see the lessons and can download the PDF in their portal. Others cannot.

## 5. Live classes

Go to **Astona > Live classes**.

1. Under **New live class**, enter the **Title**.
2. Choose the **Batch** (**Choose a batch**).
3. **Lesson ID (optional)** links the class to a lesson. Leave it blank if unsure.
4. Set **Starts** and **Ends**.
5. Choose the **Provider**: Google Meet, Zoom or Other.
6. Paste the full **Meeting link**. It must start with `https`.
7. Click **Create live class**.

**Link privacy.** The link is never shown on the public site. The students' **Join** button gives it only to students enrolled in that batch. In your list the link is shown partly hidden.

**The 15-minute window.** Students can press **Join** from 15 minutes before the start time until the end time. Before and after that, Join does not work.

**Change a link.** Click **Edit** next to the class. The current link stays hidden. Type the new full link to replace it, or leave the box blank to keep the old one. Click **Save changes**.

To remove a class, click **Delete** and confirm.

## 6. Notices

Go to **Astona > Notices**. Use this screen (not the plain **Notices** item in the main WordPress menu) so the audience check is applied. Tabs: **Draft**, **Scheduled**, **Published**, **Archived**.

### Publish a notice

1. Click **Add notice**.
2. Enter the **Title** and the message.
3. Under **Audience** choose:
   - **Public**: everyone can read it on the website. No SMS is sent.
   - **Selected batches only**: tick the batches. Only enrolled students of those batches see it.
4. Tick **Critical: also send an SMS to targeted students** only if students must be texted. This works only for **Selected batches only**.
5. To publish later, set **Schedule (site time, optional)**. Leave blank to publish now.
6. Click **Save and preview audience**. (**Save draft** saves without publishing.)
7. On **Review audience**, read the message, for example "Will be visible to 24 students in Batch A; SMS to 24 phones." Check the batch and number are right.
8. Click **Confirm and publish** (or **Confirm and schedule**). If something is wrong, click **Back to editor**.

The review step must be done within 15 minutes of previewing. If it expires, preview again.

The SMS is sent once only. Editing a notice later never texts students again.

### Wrong audience or wrong batch

- Before confirming: choose **Back to editor** and fix it. This is why the review step exists.
- After publishing: click **Archive** on the notice row to hide it, then create a new notice for the right batch. An SMS already sent cannot be taken back.
- To change the audience or the critical tick of a live notice, edit it and click **Save changes**, then confirm on **Review changes** with **Confirm and save changes**.
- To bring an archived notice back, open the **Archived** tab and click **Unarchive**.

## 7. Applications (the admission desk)

Go to **Astona > Applications**. A number badge shows how many are pending.

Every application on this list was made with a mobile number the applicant confirmed with an SMS code, so the student phone belongs to the applicant. If an applicant says the code never arrived: ask them to check the number, wait one minute and press **Resend code**; a number is limited to a few codes per hour and is locked for 15 minutes after five wrong codes. If it keeps failing, take the application at the office.

Tabs: **All**, **Pending**, **Waitlisted**, **Approved**, **Rejected**, **Cancelled**. Use **Search applications**, the batch dropdown (**All batches**), the **From** and **To** dates and **Filter** to narrow the list.

What the statuses mean:

- **Pending**: the application was submitted and is not yet paid (or a waitlist seat was offered and is not yet paid).
- **Waitlisted**: the batch was full and has **Waitlist when full** switched on. Nothing is paid yet. Open the application to see the place in line; click **Offer a seat now** to text the applicant a payment link (tick **Offer anyway** to go over capacity). Seats are also offered automatically in order when one frees up. An offer expires after the hours set in **Settings** if it is not paid; the applicant is told and the next person is offered the seat.
- **Approved**: payment was completed and the student is enrolled automatically.
- **Rejected**: you rejected it.
- **Cancelled**: no longer active.

### View an application

Click the reference number or **View**. You see the applicant's details, the invoice and payment attempts, and the enrollment. The ID number is hidden except for the last characters.

### Reject an application

1. Open the application (**View**) and scroll to **Reject application**.
2. Type the **Reason** (required, up to 500 characters). It is for your records only.
3. Click **Reject application**.

The applicant receives an SMS: "Your application was not approved. Please contact us." The reason is **not** included.

To reject several, tick them in the list, choose **Reject** from the bulk actions, enter one reason and click **Confirm reject**. Paid and non-pending ones are skipped.

**Approved or paid applications cannot be rejected.** Money has been received and the student is already enrolled. If a refund or removal is needed, talk to the owner. To stop a student attending, use **Deactivate** on the student's enrollment (section 8).

### Reveal ID number (owner only)

The owner sees a **Reveal ID number** button next to the masked number. Every reveal is recorded in the **Audit log** with who and when. Staff never see this button. Reveal only when truly needed.

### Export

**Export CSV** downloads the applications currently filtered. See section 15 for handling downloads.

## 8. Students (roster)

Go to **Astona > Students**. Search with **Search name or phone**. Filter by batch and status, then **Filter**. Columns include **Enrollment** (active or deactivated) and **Credentials SMS**.

Click a student's name or **View** to see enrollments, payments and the SMS log.

- **Resend credentials**: shown when the student has not yet set their own password. Sends a new temporary password by SMS (valid 72 hours).
- **Send login hint**: shown when the student already has a password. Sends a text explaining how to sign in with a login code. It does not reset the password.
- Both are limited to 3 per hour per student.
- **Deactivate** (on an enrollment row) stops that enrollment's access. **Reactivate** gives it back. The student's account and payment history are kept.
- **Export CSV** downloads the roster.

## 9. Payments (ledger)

Go to **Astona > Payments**. Staff can view and export. Only the owner sees **Reconcile now**.

Payment statuses: **Initiated** and **Executing** (in progress), **Completed** (paid), **Failed**, **Cancelled**, **Reconcile Needed** (the system found a problem and a person must check), **Refunded** (the money went back to the student).

**Paying in two parts.** Where a batch allows it, the applicant can pay a first part (for example 40%) at admission and the rest from their portal within the days set on the batch. Those payments show as "first part" and "balance part". The dashboard shows **Balances due**. Students are texted before and after the due date. In **Settings** the owner can choose to pause a student's access some days after the due date; access returns as soon as they pay.

**Refunds (owner).** On a completed payment click **Mark refunded**. If the gateway supports it you choose **Send the money back through the payment gateway now** (the system asks bKash, and only records the refund if bKash confirms) or **I already refunded it outside this system**. A refunded payment ends the enrolment and frees the seat (the next person on the waitlist is offered it). For a two-part fee, refund the balance part first. If you see "the gateway sent the money back but it could not be recorded", do **not** refund again: ask the technical contact.

Filter by **All statuses**, **Needs attention**, gateway, dates, or the search box (invoice, transaction ID, phone last 4). Click **View** for the event timeline.

**Needs attention** marks payments that are **Reconcile Needed**, or still Initiated/Executing after 10 minutes.

**Reconcile now** (owner): asks the payment provider what really happened and fixes the record. You will see one of these messages:

- Payment verified and settled; the application is approved.
- Already settled; nothing changed.
- The gateway does not report this payment as completed yet; nothing settled.
- Does not match the invoice (amount or state), or the batch is full: the payment stays flagged. The owner must decide with the student, for example move them to another batch.

**Export CSV** downloads the filtered list. Staff exports hide part of the phone number.

## 10. Inquiries

Go to **Astona > Inquiries** (a badge shows new ones). Tabs: **New**, **Handled**, **Spam**, **All**. Search with **Search name, phone or message**.

1. Click **View** to read the message.
2. Contact the person.
3. Optionally type a **Note (optional)**, then click **Mark handled**. Click **Mark spam** for junk. Use **Reopen** to move one back to New.

Inquiries are deleted automatically: handled and spam after 365 days, ones left as new after 730 days.

## 11. Media

Go to **Astona > Media**. All images and PDFs used on the site should be uploaded here.

1. Drop files in the box (**Drop files here or click to choose**) or click it.
2. Type **Alt text** for every image. It describes the picture for people who cannot see it. It is required.
3. Click **Upload**.

Allowed: JPEG, PNG, WebP, GIF up to 5 MB; PDF up to 10 MB. Use **Save alt** to fix alt text later. Each file has an ID number. You need it for blog images, gallery items and results.

A file marked **In use** cannot be deleted. Remove it from the page or article first.

## 12. Blog

Go to **Blog > Add New Article**.

1. Enter the title, text and category.
2. **Featured image**: type the image ID from **Astona > Media** into **Image (attachment ID)**. The link **Choose from Astona media** opens the Media screen.
3. In **Search & sharing (SEO)** fill **SEO title** (max 70 characters; blank uses the article title) and **Meta description** (max 160; blank uses the excerpt).
4. Click **Publish**, or set a future date in the editor's publish box and click **Schedule** to publish automatically.

To hide an article without deleting it, use **Archive** in the article list (**Unarchive** to restore). Archived and scheduled articles are not visible to the public.

## 13. Gallery and Results

### Gallery

1. Go to **Gallery > Add Gallery Item**.
2. In **Image**, enter the **Image (attachment ID)** from Media.
3. Fill **Alt text (required to publish)**, **Caption** and **Sort order** (lower numbers first).
4. Pick a category (Campus, Events or Results) and click **Publish**.

Without alt text, or without an image, the item is saved as a draft and not shown.

### Results (student achievements)

1. Go to **Results > Add Result**.
2. The title is the student's display name, shown exactly as typed.
3. Fill **Exam or achievement**, **Year**, **Score or grade** and, optionally, **Institution admitted (optional)**.
4. To add a photo, click **Choose file** under **Photo (optional)** (JPEG, PNG or WebP, up to 5 MB) and fill **Photo description**. The description is required with a photo: without it the photo is not saved and a red message tells you so.
5. Tick **Verified** only after checking against the official result.
6. Tick **Consent confirmed** only when you hold **written consent from the student or their guardian** to show the name and photo.
7. Click **Publish**.

A result is public only when it is published **and** verified **and** consent is confirmed.

Result photos are private. They are not in **Astona > Media** and you do not need an image ID. The website shows a photo only while the result is public, and a link to a photo stops working within moments of turning off **Consent confirmed** or **Verified**, unpublishing or trashing the result. Staff see a preview of the photo in the result form whatever its state.

To change a photo, open the result, choose a new file (and update the description) and click **Update**: the old photo is deleted. To remove it, tick **Remove the current photo** and click **Update**. Deleting a result permanently also deletes its photo. Every photo change is recorded in **Astona > Audit log**.

If a student or guardian withdraws consent: open **Results**, click **Turn off** in the **Consent** column. The name and photo leave the Results page at once.

**Never publish a student's name or photo without written guardian consent.** Keep the signed consent on file.

Photos of students without consent are never reachable from the website, but still upload a student photo only once consent is in hand, and tell the owner if one was uploaded by mistake.

## 14. Settings and audit log (owner)

**Astona > Settings**: under **Institution** enter **Name**, **Phone**, **Email** and **Address**, then click **Save settings**. These details replace the sample contact details shown on the site. Blank fields keep the sample ones, so fill all four. The Contact page text is edited separately in **Pages**. **System status** shows whether payment and SMS are test or real (see the start of this guide).

**Gateway refund window**, **Waitlist offer lasts** and **Pause access after balance is late** are in **Money and seats** on the same page; each explains itself. **Delete old personal data** is off until you tick it; below it you can see how many records it would delete. Have your lawyer confirm the periods first.

**Astona > SMS log** (owner): every text message with its status (failed first), number partly hidden, no message text. Use it to see who did not receive a login password or receipt; resend credentials from the student's page under **Students**.

**Astona > Audit log**: a record of who did what and when (rejections, reveals, exports, reconciliations, settings changes). Filter by actor, action, entity or dates. **Export CSV** is available.

## 15. When a student says...

### "I paid but have no access"

1. Go to **Astona > Payments**, set the filter to **Needs attention**, and find the payment (search by phone last 4 or invoice).
2. The owner clicks **Reconcile now**. If staff cannot see this button, pass the invoice number to the owner.
3. If the result says the payment was settled, go to **Astona > Students**, search the name or phone, open the student and click **Resend credentials**.
4. If it says "does not match" or "batch is full", the owner decides what to do. Do not promise access until then.

If the payment does not appear at all, the student did not complete payment. Remember online payment is not live yet (see the start of this guide).

### "I cannot log in"

Students sign in at `/student/login/` with their phone number and password, or with a login code (**Login with OTP**). Ask them to try the login code. Then, in **Astona > Students**, open the student and click **Send login hint** (or **Resend credentials** if they never set a password). Remind them that a temporary password lasts 72 hours. If you cannot sign in to the staff area yourself, ask the owner.

### "I got a notice for the wrong batch"

Archive the notice, publish a corrected one (the review step shows exactly who will get it) and apologise to the affected batch. See section 6.

## 16. Protecting personal data

Do:

- Export only when you need to, and only the filtered list you need.
- Delete downloaded CSV files from your computer when finished, including the Downloads folder and email attachments.
- Sign out when you leave your desk. Use your own login only.
- Get written guardian consent before publishing any student's name or photo.

Do not:

- Share exports, photos or ID numbers by WhatsApp, email or social media.
- Share your password, or ask the owner for an ID number you do not need.
- Copy student details into personal notes or spreadsheets.
- Promise students things the system does not do yet (online payment, SMS).

## 17. Glossary

- **Application**: a student's admission form for one batch.
- **Batch**: one running group of a course, with its own schedule, price, capacity and start date.
- **Enrollment**: a student's place in a batch. It can be active or deactivated.
- **Invoice**: the bill created for an application.
- **Reconcile**: check with the payment provider what really happened to a payment and fix the record.
- **Gateway**: the payment provider (bKash). Currently a test version.
- **OTP / login code**: a 6-digit code texted to the student's phone, valid 5 minutes.
- **Critical notice**: a batch notice that also sends an SMS.
- **Alt text**: a short description of an image for people who cannot see it.
- **Audit log**: the permanent record of staff actions.
- **Archive**: hide something from the public without deleting it.
- **Consent**: written permission from the student or guardian to publish their name or photo.
