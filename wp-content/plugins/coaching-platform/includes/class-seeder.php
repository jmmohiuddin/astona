<?php
defined( 'ABSPATH' ) || exit;

/**
 * `wp cc seed` — idempotent sample content for local/staging. All names and contacts are synthetic.
 */
final class CC_Seeder {

	public static function run(): void {
		global $wpdb;

		CC_Migrations::run();
		CC_Post_Types::register();

		$cats = array();
		foreach ( array(
			'academic'   => 'SSC / HSC Academic',
			'admission'  => 'University Admission',
			'skill'      => 'Skill Development',
			'language'   => 'Language & IELTS',
		) as $slug => $name ) {
			$term = term_exists( $slug, 'cc_category' ) ?: wp_insert_term( $name, 'cc_category', array( 'slug' => $slug ) );
			if ( is_wp_error( $term ) ) {
				self::fail( 'Could not create category ' . $slug . ': ' . $term->get_error_message() );
			}
			$cats[ $slug ] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		$faculty = array();
		foreach ( array(
			'dr-rahim-uddin' => array( 'Dr. Rahim Uddin', 'Physics', 'PhD, Dhaka University · 15 years teaching' ),
			'nusrat-jahan'   => array( 'Nusrat Jahan', 'English & IELTS', 'MA English, 8.0 IELTS · 9 years teaching' ),
			'tanvir-ahmed'   => array( 'Tanvir Ahmed', 'Mathematics', 'BSc Mathematics, BUET · 11 years teaching' ),
			'sadia-karim'    => array( 'Sadia Karim', 'Web Development', 'BSc CSE · Senior developer, 7 years industry' ),
		) as $slug => $f ) {
			$id = self::upsert( 'cc_faculty', $slug, array( 'post_title' => $f[0], 'post_content' => $f[0] . ' teaches ' . $f[1] . ' at Astona.' ) );
			update_post_meta( $id, 'cc_subject', $f[1] );
			update_post_meta( $id, 'cc_credentials', $f[2] );
			$faculty[ $slug ] = $id;
		}

		$next = static fn( int $days ): string => gmdate( 'Y-m-d', strtotime( "+{$days} days" ) );

		$courses = array(
			'hsc-science-2027'   => array(
				'title'    => 'HSC Science Batch 2027 · এইচএসসি বিজ্ঞান',
				'excerpt'  => 'Complete HSC Physics, Chemistry, Math and Biology preparation with weekly exams and doubt-clearing sessions.',
				'cat'      => 'academic',
				'duration' => '12 months',
				'syllabus' => "Physics 1st & 2nd paper\nChemistry 1st & 2nd paper\nHigher Mathematics\nBiology\nWeekly model tests",
				'faculty'  => array( 'dr-rahim-uddin', 'tanvir-ahmed' ),
				'batches'  => array(
					array( 'Morning Batch', 'physical', 40, 14000, $next( 14 ), 'Sat, Mon, Wed · 7:00–9:00 AM', 33 ),
					array( 'Evening Online', 'online', 60, 11000, $next( 14 ), 'Sun, Tue, Thu · 8:00–10:00 PM', 20 ),
				),
			),
			'ssc-math-booster'   => array(
				'title'    => 'SSC Math Booster · এসএসসি গণিত',
				'excerpt'  => 'Concept-first mathematics for SSC candidates, with chapter tests and board-question practice.',
				'cat'      => 'academic',
				'duration' => '5 months',
				'syllabus' => "Algebra\nGeometry\nTrigonometry\nStatistics\nBoard question practice",
				'faculty'  => array( 'tanvir-ahmed' ),
				'batches'  => array(
					array( 'Batch A', 'physical', 30, 6000, $next( 7 ), 'Fri, Sat · 4:00–6:00 PM', 30 ),
				),
			),
			'university-admission-2027' => array(
				'title'    => 'University Admission Crash Course · ভর্তি প্রস্তুতি',
				'excerpt'  => 'Intensive preparation for public university admission tests with mock exams and ranked feedback.',
				'cat'      => 'admission',
				'duration' => '4 months',
				'syllabus' => "Physics & Chemistry revision\nMathematics shortcuts\nEnglish\nGeneral knowledge\nFull-length mock tests",
				'faculty'  => array( 'dr-rahim-uddin', 'nusrat-jahan' ),
				'batches'  => array(
					array( 'Hybrid Batch', 'hybrid', 80, 18000, $next( 21 ), 'Daily · 3:00–6:00 PM', 12 ),
				),
			),
			'ielts-academic'     => array(
				'title'    => 'IELTS Academic Preparation',
				'excerpt'  => 'Four-skills IELTS coaching with weekly speaking practice and full mock tests.',
				'cat'      => 'language',
				'duration' => '10 weeks',
				'syllabus' => "Listening strategies\nAcademic reading\nWriting task 1 & 2\nSpeaking practice\nFull mock tests",
				'faculty'  => array( 'nusrat-jahan' ),
				'batches'  => array(
					array( 'Weekend Batch', 'physical', 20, 9500, $next( 10 ), 'Fri, Sat · 10:00 AM–1:00 PM', 20 ),
					array( 'Online Batch', 'online', 40, 8000, $next( 10 ), 'Mon, Wed · 8:00–10:00 PM', 5 ),
				),
			),
			'web-development-bootcamp' => array(
				'title'    => 'Web Development Bootcamp · ওয়েব ডেভেলপমেন্ট',
				'excerpt'  => 'Learn HTML, CSS, JavaScript and WordPress by building real projects, with career guidance at the end.',
				'cat'      => 'skill',
				'duration' => '6 months',
				'syllabus' => "HTML & CSS\nJavaScript fundamentals\nPHP & WordPress\nGit & deployment\nCapstone project",
				'faculty'  => array( 'sadia-karim' ),
				'batches'  => array(
					array( 'Batch 1 (Closed)', 'physical', 25, 24000, $next( -30 ), 'Sat, Tue · 6:00–8:30 PM', 25, 'closed', false ),
				),
			),
		);

		foreach ( $courses as $slug => $c ) {
			$id = self::upsert( 'cc_course', $slug, array( 'post_title' => $c['title'], 'post_excerpt' => $c['excerpt'], 'post_content' => '<p>' . esc_html( $c['excerpt'] ) . '</p>' ) );
			wp_set_object_terms( $id, array( $cats[ $c['cat'] ] ), 'cc_category' );
			update_post_meta( $id, 'cc_duration', $c['duration'] );
			update_post_meta( $id, 'cc_syllabus', $c['syllabus'] );
			update_post_meta( $id, 'cc_instructors', array_map( static fn( $s ) => $faculty[ $s ], $c['faculty'] ) );

			if ( CC_Batch_Repository::for_course( $id ) ) {
				continue; // Keep existing batches (and live seat counts) on re-seed.
			}
			$rows = array();
			foreach ( $c['batches'] as $b ) {
				$rows[] = array(
					'name' => $b[0], 'delivery_mode' => $b[1], 'capacity' => $b[2], 'price' => $b[3],
					'start_date' => $b[4], 'end_date' => '', 'schedule_text' => $b[5],
					'status' => $b[7] ?? 'open', 'application_open' => $b[8] ?? true,
				);
			}
			CC_Batch_Repository::sync( $id, $rows );
			$seats_by_name = array_column( $c['batches'], 6, 0 );
			foreach ( CC_Batch_Repository::for_course( $id ) as $saved ) {
				$wpdb->update( CC_Migrations::table(), array( 'seats_taken' => $seats_by_name[ $saved['name'] ] ?? 0 ), array( 'id' => $saved['id'] ) );
			}
		}

		foreach ( array(
			'hsc-2027-admission-open' => array( 'HSC 2027 admission is now open', 'Applications for the HSC Science Batch 2027 are open. Seats are limited; the morning batch is filling fast.' ),
			'ielts-weekend-batch'     => array( 'New IELTS weekend batch starts soon', 'A new weekend IELTS batch begins in ten days. Orientation is free for registered students.' ),
			'holiday-notice'          => array( 'Office closed on public holiday', 'Our office will be closed on the upcoming public holiday. Classes resume as scheduled.' ),
		) as $slug => $n ) {
			self::upsert( 'cc_notice', $slug, array( 'post_title' => $n[0], 'post_excerpt' => $n[1], 'post_content' => '<p>' . esc_html( $n[1] ) . '</p>' ) );
		}

		$pages = array(
			'home'       => array( 'Home', '' ),
			'about'      => array( 'About Astona', '<p>Astona is a coaching center built around clear teaching, small batches and honest results. We prepare students for board exams, university admission and the skills employers look for.</p><h2>What we stand for</h2><ul><li><strong>Clarity</strong> — concept-first teaching, not rote learning.</li><li><strong>Care</strong> — small batches and direct access to instructors.</li><li><strong>Convenience</strong> — physical, online and hybrid batches.</li></ul>' ),
			'faq'        => array( 'Frequently Asked Questions', '<details><summary>How do I apply for a course?</summary><p>Open a course page, choose a batch and press Apply Now. You can complete the admission and payment in one visit.</p></details><details><summary>Can I pay with bKash?</summary><p>Yes. Online admission with bKash payment is being enabled soon; until then please contact our office.</p></details><details><summary>Do you offer online classes?</summary><p>Yes. Many courses have online or hybrid batches.</p></details><details><summary>What if a batch is full?</summary><p>Closed batches cannot take new applications. Contact us to be told about the next batch.</p></details>' ),
			'contact'    => array( 'Contact Us', '<p>Visit or call us during office hours, Saturday to Thursday, 9:00 AM – 8:00 PM.</p><ul><li><strong>Address:</strong> Sample address, Dhaka, Bangladesh</li><li><strong>Phone:</strong> +880 1700-000000</li><li><strong>Email:</strong> info@astona.example</li></ul><p><em>Sample contact details — replace in the page editor before launch.</em></p>' ),
			'admissions' => array( 'Admissions', '' ),
			'privacy'    => self::legal_pages()['privacy'],
			'terms'      => self::legal_pages()['terms'],
		);
		$page_ids = array();
		foreach ( $pages as $slug => $p ) {
			$page_ids[ $slug ] = self::upsert( 'page', $slug, array( 'post_title' => $p[0], 'post_content' => $p[1] ) );
		}

		if ( ! (int) get_option( 'page_on_front' ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_ids['home'] );
		}
		if ( in_array( get_option( 'blogdescription' ), array( '', 'Just another WordPress site' ), true ) ) {
			update_option( 'blogdescription', 'Learn with clarity. Grow with confidence.' );
		}

		self::build_menu( $page_ids );
		flush_rewrite_rules();

		// Sub-project 6 modules add their own sample content here (blog, gallery, results, branches, menu items).
		do_action( 'cc_seed_extra' );

		do_action( 'cc_catalogue_changed', 0 );
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::success( 'Astona sample content is ready.' );
		}
	}

	/* ------------------------------------------------------------------------------------------------------------
	 * DRAFT legal pages (Privacy Policy, Terms & Conditions).
	 * Built from what the system actually does (see docs/LEGAL-DRAFTS.md for the statement-to-code map).
	 * NOT legal advice: a qualified Bangladeshi lawyer must review them before launch.
	 * ------------------------------------------------------------------------------------------------------------ */

	/** Visible marker used both in the banner and by `wp cc refresh-legal` to recognise unreviewed drafts. */
	const LEGAL_DRAFT_MARKER = 'DRAFT — for legal review before launch';

	/** Text of the pre-draft placeholders (so refresh-legal can recognise a page nobody has edited). */
	const LEGAL_OLD_PLACEHOLDERS = array(
		'privacy' => 'This is a placeholder privacy policy.',
		'terms'   => 'This is a placeholder terms page.',
	);

	/** @return array<string,array{0:string,1:string}> slug => title, content for the two legal pages. */
	public static function legal_pages(): array {
		return array(
			'privacy' => array( 'Privacy Policy', self::legal_privacy_html() ),
			'terms'   => array( 'Terms & Conditions', self::legal_terms_html() ),
		);
	}

	private static function legal_banner(): string {
		return '<div class="cc-legal-draft" role="note" style="border:3px solid #b91c1c;background:#fee2e2;color:#7f1d1d;padding:12px 16px;border-radius:12px;margin:0 0 1.5em;"><strong>' . self::LEGAL_DRAFT_MARKER . '</strong><br><span>This text was drafted from how the system behaves and has not been reviewed by a lawyer. Items marked [OWNER TO CONFIRM] are decisions the owner still has to make. Staff: remove this box only after legal review (see docs/LEGAL-DRAFTS.md).</span></div>';
	}

	private static function legal_privacy_html(): string {
		return self::legal_banner() . <<<'HTML'
<div style="border:2px solid #4338ca;background:#eef2ff;padding:14px 18px;border-radius:12px;margin:0 0 1.5em;">
<p><strong>সংক্ষেপে বাংলায় (Bangla summary)</strong></p>
<ul>
<li>ভর্তির আবেদনে আমরা আপনার নাম, জন্ম তারিখ, পরিচয়পত্রের তথ্য, ছবি, ফোন নম্বর, অভিভাবকের তথ্য ও শিক্ষাপ্রতিষ্ঠানের তথ্য নিই। এগুলো শুধু ভর্তি, ক্লাস ও যোগাযোগের কাজে ব্যবহার হয়।</li>
<li>পরিচয়পত্রের নম্বর এনক্রিপ্ট করে রাখা হয় এবং ছবি সুরক্ষিত (ব্যক্তিগত) স্থানে থাকে।</li>
<li>কার্ড বা মোবাইল ব্যাংকিংয়ের পিন ও পাসওয়ার্ড আমরা কখনো দেখি না। পেমেন্ট হয় পেমেন্ট গেটওয়ের নিজস্ব পাতায়।</li>
<li>এই সাইটে বিজ্ঞাপন বা অ্যানালিটিক্স কুকি নেই।</li>
<li>অভিভাবকের লিখিত সম্মতি ছাড়া কোনো শিক্ষার্থীর ফলাফল বা ছবি প্রকাশ করা হয় না।</li>
<li>আপনার তথ্য দেখতে, ঠিক করতে বা মুছে ফেলার অনুরোধ করতে আমাদের সাথে যোগাযোগ করুন।</li>
</ul>
</div>

<p><em>Last updated: [OWNER TO CONFIRM: date of final version]</em></p>

<h2>1. Who we are</h2>
<p>This site is run by Astona, a coaching center in Bangladesh ("Astona", "we", "us"). This policy explains what personal information we collect through this website and student portal, why we collect it, who else sees it, how long we keep it and what choices you have.</p>
<p>Registered name and address: [OWNER TO CONFIRM: registered business name and registered address].</p>

<h2>2. Who this policy is about</h2>
<p>Most of our students are under 18. The admission form asks for a parent or guardian's name and phone number, and applying means a parent or guardian agrees to this policy on the student's behalf. [OWNER TO CONFIRM: wording for guardian consent and how we treat the data of children under 18, for example who may apply and what proof of guardianship, if any, we ask for.]</p>

<h2>3. What we collect and why</h2>

<h3>3.1 Admission application</h3>
<p>When you apply for a batch, the form collects:</p>
<ul>
<li>student's full name, gender and date of birth;</li>
<li>ID document type and ID number (birth registration, NID, passport or similar);</li>
<li>a photograph of the student;</li>
<li>student's phone number and guardian's name and phone number;</li>
<li>email address (optional);</li>
<li>institution, class or level, passing year (optional), roll number (optional);</li>
<li>preferred branch (optional);</li>
<li>the time you ticked the consent box.</li>
</ul>
<p><strong>Why:</strong> to review your application, confirm your identity, reserve a seat, create your student account, contact you and your guardian, and keep a record of who is enrolled.</p>

<h3>3.2 Payments</h3>
<p>We keep an invoice for each application and a record of each payment attempt: invoice number, amount, status, payment method, the gateway's payment and transaction identifiers, and the gateway's response. <strong>We never see or store your card number, mobile-banking account number, PIN or password.</strong> You enter those on the payment gateway's own page. The gateway is not connected yet (bKash is planned). Until then payment is recorded by our staff at the office.</p>
<p><strong>Why:</strong> to confirm that you paid, send a receipt and keep accounts.</p>

<h3>3.3 Student account</h3>
<p>When an application is approved or paid we create an account using your phone number as the login and a password. Passwords are stored only as a one-way hash, so staff cannot read them. We also keep your name, guardian details, institution, photo and the batches you are enrolled in, and the dates of enrolment.</p>
<p><strong>Why:</strong> so you can log in, see your courses, notices, receipts and live classes.</p>

<h3>3.4 SMS messages</h3>
<p>We send SMS to your phone for: your first login details, one-time login codes (OTP), payment receipts, notice that a course was added to your account, a hint on how to log in, important (critical) notices, and a message when an application is not approved.</p>
<p>The text of messages that contain login credentials or OTP codes is <strong>never stored</strong>. For the other messages we keep a log with the phone number, message type, delivery status and an encrypted copy of the text. OTP codes are stored only as a hash and expire after 5 minutes.</p>
<p><strong>Why:</strong> to let you sign in, tell you about payments and enrolment, and reach you with urgent information.</p>

<h3>3.5 Live-class join log</h3>
<p>When a student joins an online class through the portal we record which class, which student, the time, and a hashed (not readable) form of the internet address. <strong>Why:</strong> to see who attended and to investigate misuse of class links.</p>

<h3>3.6 Staff activity (audit log)</h3>
<p>When staff change important records (for example approving an application or changing a result) we record who did it, what they did, when, and a hashed form of their internet address. <strong>Why:</strong> security and accountability.</p>

<h3>3.7 Contact form</h3>
<p>The contact form collects your name, phone number, optional email, topic, optional course and your message, and a hashed form of your internet address for abuse prevention. <strong>Why:</strong> to answer your question.</p>

<h3>3.8 Results and gallery</h3>
<p>We publish a student's name, photo or result on the public Results or Gallery pages <strong>only when the student or guardian has given written consent</strong>, and staff have verified the result. A record without confirmed consent is not shown. You may withdraw consent at any time by contacting us and we will remove the record from the public pages.</p>

<h3>3.9 Cookies</h3>
<p>The site sets the cookies WordPress needs to keep students and staff logged in. Visitors who are not logged in do not receive login cookies. <strong>We do not use analytics, advertising or tracking cookies.</strong> If we add any in future we will update this policy and ask for your consent where required. Fonts are hosted on our own server, not loaded from a third party.</p>

<h2>4. Who else receives your information</h2>
<p>We do not sell your personal information. We share it only with the service providers needed to run the site:</p>
<ul>
<li><strong>Payment gateway:</strong> [OWNER TO CONFIRM: gateway name; bKash is planned and not yet connected]. It receives the amount and what is needed to take your payment. You enter payment details on its page.</li>
<li><strong>SMS gateway:</strong> [OWNER TO CONFIRM: provider; not yet chosen]. It receives the phone number and text of each SMS, including OTP and credential messages while they are being delivered.</li>
<li><strong>Cloudflare Turnstile:</strong> on forms where it is switched on (currently the Contact form), a Cloudflare check runs in your browser to block bots. Cloudflare receives technical information such as your internet address. If we also use Cloudflare to protect or speed up the site, it handles traffic to the site.</li>
<li><strong>Maps:</strong> the Contact page may show a map embedded from OpenStreetMap or Google Maps. Those providers can see your internet address when the map loads.</li>
<li><strong>Hosting provider:</strong> [OWNER TO CONFIRM: hosting company and server location]. It stores the site and database.</li>
</ul>
<p>We may also disclose information when the law requires it. [OWNER TO CONFIRM: any other recipients, for example auditors or government bodies.]</p>

<h2>5. How long we keep information</h2>
<ul>
<li><strong>Login codes (OTP):</strong> deleted automatically about an hour after they expire.</li>
<li><strong>Contact inquiries:</strong> inquiries marked handled or spam are deleted automatically 365 days after they were received. Inquiries nobody has handled are deleted after 730 days.</li>
<li><strong>Admission applications, enrolment, student account, payment and invoice records, SMS log, join log and audit log:</strong> [OWNER TO CONFIRM: how long each is kept and what is deleted or anonymised afterwards. The system does not delete these automatically today.]</li>
<li><strong>Result and gallery records:</strong> shown only while consent stands; removed from public pages on request.</li>
</ul>

<h2>6. How we protect your information</h2>
<ul>
<li>ID numbers are encrypted in the database.</li>
<li>Meeting links for live classes are encrypted, shown only to students enrolled in that batch through the portal, and never put in public pages.</li>
<li>Student photos are re-saved without hidden metadata and kept in private storage outside the public web folder.</li>
<li>Passwords are stored only as hashes. OTP codes are stored only as hashes.</li>
<li>Internet addresses in logs are stored only as hashes.</li>
<li>Access is limited by role: owner, staff and instructors see only what their work needs, and instructors only see their own batches.</li>
<li>Important staff actions are written to an audit log.</li>
<li>Login, OTP, contact and admission requests are rate-limited, and forms include bot protection, to reduce abuse.</li>
</ul>
<p>No system is perfectly secure. If we learn of a breach that affects you we will tell you as the law requires. [OWNER TO CONFIRM: breach notification process.]</p>

<h2>7. Your choices and rights</h2>
<p>You (or your guardian, if you are under 18) may ask us to:</p>
<ul>
<li>show you the information we hold about you;</li>
<li>correct information that is wrong;</li>
<li>delete your information, or withdraw your consent for publishing your name, photo or result.</li>
</ul>
<p>Send the request using the contact details below. We may need to confirm who you are first. Some records (for example payment records) may have to be kept for accounting or legal reasons. [OWNER TO CONFIRM: response time, for example 30 days, and which records are kept after a deletion request.] Bangladesh's data-protection rules are still developing, so this policy may change as the law changes.</p>

<h2>8. Refunds and other policies</h2>
<p>Fees and refunds are covered in our Terms &amp; Conditions. [OWNER TO CONFIRM: refund policy (open question OQ-06).]</p>

<h2>9. Changes to this policy</h2>
<p>We may update this policy. The date at the top shows the latest version. If a change is significant we will tell students by notice or SMS. [OWNER TO CONFIRM: how and when users are told.]</p>

<h2>10. Governing law</h2>
<p>[OWNER TO CONFIRM: governing law and the courts that handle disputes, as advised by the lawyer.]</p>

<h2>11. Contact us</h2>
<p>For questions or requests about your information, use the phone number, email and address on our <a href="/contact/">Contact page</a>. Those details are taken from the Settings page in the site's admin area, so they stay up to date automatically. Data-protection contact: [OWNER TO CONFIRM: person or role and a dedicated email address].</p>
HTML;
	}

	private static function legal_terms_html(): string {
		return self::legal_banner() . <<<'HTML'
<div style="border:2px solid #4338ca;background:#eef2ff;padding:14px 18px;border-radius:12px;margin:0 0 1.5em;">
<p><strong>সংক্ষেপে বাংলায় (Bangla summary)</strong></p>
<ul>
<li>ভর্তির সময় পুরো ফি একসাথে পরিশোধ করতে হয়।</li>
<li>ফি ফেরতের নিয়ম [মালিক নিশ্চিত করবেন]।</li>
<li>ব্যাচে আসন সীমিত। আসন পূর্ণ হলে ভর্তি বন্ধ হয়ে যায়। আমরা কোনো পরীক্ষার ফলাফলের নিশ্চয়তা দিই না।</li>
<li>আপনার লগইন অন্য কাউকে দেবেন না। লাইভ ক্লাসের লিংক শুধু আপনার জন্য, কারও সাথে শেয়ার করবেন না।</li>
<li>কোর্সের নোট ও পিডিএফ শুধু আপনার নিজের পড়ার জন্য। অন্য কাউকে দেওয়া বা কপি করে ছড়ানো যাবে না।</li>
<li>লাইভ ক্লাসে ভদ্র আচরণ করতে হবে।</li>
<li>ভর্তি, পেমেন্ট ও জরুরি নোটিশের জন্য আমরা এসএমএস পাঠাই।</li>
</ul>
</div>

<p><em>Last updated: [OWNER TO CONFIRM: date of final version]</em></p>

<h2>1. About these terms</h2>
<p>These terms apply when you use this website, apply to a course or use the student portal run by Astona, a coaching center in Bangladesh ("Astona", "we", "us"). If the student is under 18, a parent or guardian must accept these terms for the student, and "you" includes the guardian. By applying or logging in you agree to them. Please also read our <a href="/privacy/">Privacy Policy</a>.</p>

<h2>2. Admission and payment</h2>
<ul>
<li>Applications are made online or at the office for a specific batch. Submitting an application does not guarantee a seat. We may approve or reject an application, and we will tell you by SMS if it is not approved.</li>
<li>Each batch has a limited number of seats. When a batch is full or closed, it stops taking applications. [OWNER TO CONFIRM: waiting-list or next-batch arrangements.]</li>
<li>The course fee is paid in full at admission. Part payment and instalments are not offered. [OWNER TO CONFIRM: fee amounts are shown on each course page; confirm whether any discount or instalment policy exists.]</li>
<li>Online payment is made on the payment gateway's page. We never see your card or mobile-banking details. Online payment by bKash is not yet available; until it is, please pay at the office. Payment is final once we confirm it and send you a receipt.</li>
<li>Your seat and account are created after payment is confirmed. If you paid and did not receive confirmation, contact us with your transaction ID.</li>
</ul>

<h2>3. Refunds</h2>
<p>[OWNER TO CONFIRM: refund policy (open question OQ-06). Decide whether fees are refundable, in which cases (for example batch cancelled by us, duplicate payment, withdrawal before the first class), the time limits, any deduction, and how refunds are paid.] Until the owner decides, no refund promise is made by this page.</p>

<h2>4. No guarantee of results</h2>
<p>We teach to the best of our ability, but exam results and admission to any institution depend on the student's own effort and many other factors. We do not guarantee any particular result, grade or admission.</p>

<h2>5. Your account</h2>
<ul>
<li>Your account is personal to the enrolled student. Do not share your phone login, password or login codes with anyone.</li>
<li>You are responsible for what happens under your account. Tell us at once if you think someone else has used it.</li>
<li>Keep your phone number up to date. We use it to send login codes and notices.</li>
<li>We may suspend or deactivate an account or enrolment that breaks these terms or when enrolment ends.</li>
</ul>

<h2>6. Live classes</h2>
<ul>
<li>Live class links are personal to you. Do not copy, forward, post or share them with anyone, including other students.</li>
<li>Join through the student portal. Joining is logged (time and a hashed internet address).</li>
<li>Behave respectfully towards teachers and classmates. No abuse, harassment, disruption or inappropriate content.</li>
<li>Do not record, screenshot or share the class unless we have said you may. [OWNER TO CONFIRM: recording policy.]</li>
<li>We may remove a person from a class or deactivate their enrolment for misconduct, without refund unless the refund policy says otherwise.</li>
</ul>

<h2>7. Acceptable use</h2>
<p>When using the site you must not: give false information; use another person's identity or documents; try to access accounts, data or areas you are not allowed to; interfere with the site's security or operation (including scraping, automated sign-ups or sending spam through the forms); or upload anything unlawful.</p>

<h2>8. Course materials and our content</h2>
<p>Notes, PDFs, videos, lesson text and other course materials are owned by Astona or its teachers. You may use them for your own study only. You must not copy, sell, upload, share or publish them, in whole or in part, without our written permission.</p>

<h2>9. Notices and SMS</h2>
<p>By giving us your phone number you agree that we may send you SMS about your application, login codes, payment receipts, enrolment and important notices. Standard SMS charges from your operator may apply. Important notices are also published in the Notices section and your portal. [OWNER TO CONFIRM: how a student can stop non-essential SMS.]</p>

<h2>10. Results and photos</h2>
<p>We publish names, photos or results of students only with written consent from the student or guardian, as described in the Privacy Policy.</p>

<h2>11. Limitation of liability</h2>
<p>To the extent the law allows, Astona is not responsible for indirect or consequential losses, or for interruptions to the site, internet, SMS or third-party services that we do not control. Nothing in these terms limits any right you have under the law that cannot be limited. [OWNER TO CONFIRM: wording as advised by the lawyer.]</p>

<h2>12. Changes to these terms</h2>
<p>We may update these terms. The date at the top shows the latest version. If you keep using the site after a change, you accept the new terms. For significant changes we will tell enrolled students by notice or SMS.</p>

<h2>13. Governing law</h2>
<p>[OWNER TO CONFIRM: governing law and the courts that handle disputes, as advised by the lawyer.]</p>

<h2>14. Contact us</h2>
<p>For questions about these terms, use the phone number, email and address on our <a href="/contact/">Contact page</a>. Those details are taken from the Settings page in the site's admin area.</p>
HTML;
	}

	/**
	 * `wp cc refresh-legal` — replaces the privacy and terms pages with the current drafts, but only when the page
	 * still holds the old placeholder or the unreviewed draft banner. Owner-edited final text is never overwritten.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 */
	public static function refresh_legal( $args = array(), $assoc_args = array() ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		foreach ( self::legal_pages() as $slug => $page ) {
			$post = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $post instanceof WP_Post ) {
				self::legal_report( 'warning', $slug . ': page does not exist; run `wp cc seed` first.' );
				continue;
			}
			$content = (string) $post->post_content;
			if ( ! self::legal_is_refreshable( $slug, $content ) ) {
				self::legal_report( 'line', $slug . ': skipped (content is owner-edited final text).' );
				continue;
			}
			if ( $content === $page[1] ) {
				self::legal_report( 'line', $slug . ': already up to date.' );
				continue;
			}
			if ( $dry_run ) {
				self::legal_report( 'line', $slug . ': would update (dry run).' );
				continue;
			}
			$result = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $page[1] ) ), true );
			if ( is_wp_error( $result ) ) {
				self::fail( $slug . ': update failed: ' . $result->get_error_message() );
			}
			self::legal_report( 'success', $slug . ': updated.' );
		}
	}

	/** True when the page is empty, still the old placeholder, or still carries the draft banner. */
	public static function legal_is_refreshable( string $slug, string $content ): bool {
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return true;
		}
		$old = self::LEGAL_OLD_PLACEHOLDERS[ $slug ] ?? '';
		return ( '' !== $old && false !== strpos( $content, $old ) ) || false !== strpos( $content, self::LEGAL_DRAFT_MARKER );
	}

	private static function legal_report( string $level, string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			'success' === $level ? WP_CLI::success( $message ) : ( 'warning' === $level ? WP_CLI::warning( $message ) : WP_CLI::line( $message ) );
		}
	}

	private static function upsert( string $type, string $slug, array $args ): int {
		$found = get_posts( array( 'post_type' => $type, 'name' => $slug, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), 'posts_per_page' => 1, 'fields' => 'ids' ) );
		if ( $found ) {
			return (int) $found[0];
		}
		$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_name' => $slug, 'post_status' => 'publish' ), $args ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			self::fail( 'Could not create ' . $type . ' ' . $slug );
		}
		return (int) $id;
	}

	private static function fail( string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::error( $message );
		}
		throw new RuntimeException( $message );
	}

	private static function build_menu( array $page_ids ): void {
		$menu = wp_get_nav_menu_object( 'Primary' );
		if ( $menu ) {
			return;
		}
		$menu_id = wp_create_nav_menu( 'Primary' );
		$items   = array(
			array( 'Courses', get_post_type_archive_link( 'cc_course' ) ),
			array( 'About', get_permalink( $page_ids['about'] ) ),
			array( 'Notices', get_post_type_archive_link( 'cc_notice' ) ),
			array( 'Faculty', get_post_type_archive_link( 'cc_faculty' ) ),
			array( 'FAQ', get_permalink( $page_ids['faq'] ) ),
			array( 'Contact', get_permalink( $page_ids['contact'] ) ),
		);
		foreach ( $items as $item ) {
			wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => $item[0], 'menu-item-url' => $item[1], 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) );
		}
		$locations            = (array) get_theme_mod( 'nav_menu_locations', array() );
		$locations['primary'] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'cc refresh-legal', array( 'CC_Seeder', 'refresh_legal' ) );
}
