<?php
/**
 * Home page of the online edition (Olivia Jóga – Online): live online classes from home.
 * Olivia, 11.13: the hero in the style of oliviajoga.hu (the breathing lilac block, the soft pink wave, green buttons,
 * room for the accent of JÓGA), her title and specialities on the name card, five separate service boxes, no
 * „magyarul” anywhere, „Bejelentkezés és árak” instead of „Órarend és árak”.
 * The intro copy comes from the Home page's content in the editor.
 */

defined( 'ABSPATH' ) || exit;

$studio = fn( $sc ) => shortcode_exists( $sc ) ? do_shortcode( '[' . $sc . ']' ) : '';
// Olívia, 14 November: „az egyéni online jógaórát és a kismamatornát a főoldalon cseréljük meg” – prenatal before one-to-one, here and in the blocks below
$ways   = array(
	array( __( 'Group online flow yoga', 'olivia-yoga' ), __( 'Every Wednesday, 18:30 to 20:00, live. Beginners welcome.', 'olivia-yoga' ), oy_class_url( 'hatha-flow' ), 'group', 'lilac', 'hatha-flow' ),
	array( __( 'Prenatal fitness online', 'olivia-yoga' ), __( 'One-to-one or with a friend: breathing, pelvic floor, circulation, cardio, strength, spine, mobility, yoga and relaxation, combined for your trimester.', 'olivia-yoga' ), oy_class_url( oy_prenatal_slug() ), 'prenatal', 'sage', oy_prenatal_slug() ),
	array( __( 'Individual online yoga', 'olivia-yoga' ), __( 'One-to-one on video: for a sore back, for stress, or to learn yoga from the basics.', 'olivia-yoga' ), oy_class_url( 'egyeni-online-joga' ), 'home', 'pink', 'egyeni-online-joga' ),
	array( __( 'Stretching & mobility', 'olivia-yoga' ), __( 'One-to-one stretching and mobility work for a stiff hip, shoulder and spine, at your own pace.', 'olivia-yoga' ), oy_class_url( 'stretching' ), 'mobility', 'sun', 'stretching' ),
	array( __( 'Relaxation & breathing', 'olivia-yoga' ), __( 'Half an hour of breathing and guided relaxation for an overloaded nervous system, even at the end of a long day.', 'olivia-yoga' ), oy_class_url( 'relaxacio-legzes' ), 'lotus', 'lilac', 'relaxacio-legzes' ),
);
$why    = array(
	array( 'home', __( 'No courage needed for the first class', 'olivia-yoga' ), __( 'Nervous about your first yoga class? Try it at home, on your own mat. Nobody is watching, and your camera can stay off if you like.', 'olivia-yoga' ), 'lilac' ),
	array( 'clock', __( 'No travel', 'olivia-yoga' ), __( 'No traffic, no parking, no queue in the changing room. At home you can get ready in peace: light a candle, or create whatever helps you arrive.', 'olivia-yoga' ), 'pink' ),
	array( 'group', __( 'The power of the group', 'olivia-yoga' ), __( 'You are at home, but the motivation stays: the class is live, you need to be there on time, and the others are waiting for you.', 'olivia-yoga' ), 'sage' ),
	array( 'user', __( 'A real teacher, present', 'olivia-yoga' ), __( 'You are not following a video: you get live cues, you can ask questions and get feedback, and the teacher can respond to what the group needs.', 'olivia-yoga' ), 'sun' ),
);
$needs  = array(
	array( 'home', __( 'A quiet corner', 'olivia-yoga' ), __( 'Room for a mat, and a door you can close for an hour.', 'olivia-yoga' ), 'lilac' ),
	// Olívia, 14 November: „ezt kéne átírni általánosra, mert a kismamatornához nem ezek kellenek” – and „ahova illik, írd oda,
	// hogy ne felejtse el feltölteni az eszközét, mielőtt becsatlakozik az órára”
	array( 'lotus', __( 'A mat, and what your class asks for', 'olivia-yoga' ), __( 'Every class needs a mat. What else you need depends on the class: you will find it in its description.', 'olivia-yoga' ), 'pink' ),
	array( 'online', __( 'A charged phone, tablet or laptop', 'olivia-yoga' ), __( 'A camera and a browser are enough, no app. Charge it before you join, so it does not run out during class.', 'olivia-yoga' ), 'sage' ),
	// Olívia, 14 November: „szedjük ki mindenhonnan azt, hogy a képét csak én látom. a későbbi felvételen csak én látom, de
	// élőben láthassuk egymást, ha valaki bekapcsolja a kameráját”
	array( 'group', __( 'Camera on, if you like', 'olivia-yoga' ), __( 'It is not compulsory. If you turn it on, we can see each other live, and I can help you with the poses. Only I am on the recording.', 'olivia-yoga' ), 'sun' ),
);
$steps  = array(
	array( __( 'Choose a class', 'olivia-yoga' ), __( 'Book from the timetable with a drop-in or a class pass.', 'olivia-yoga' ) ),
	array( __( 'Get the link', 'olivia-yoga' ), __( 'Your own link comes by email and is waiting in your account. It opens in the browser.', 'olivia-yoga' ) ),
	array( __( 'Roll out your mat', 'olivia-yoga' ), __( 'Charge your device and join a few minutes early, so we can say hello before we start.', 'olivia-yoga' ) ),
	array( __( 'Practice together, live', 'olivia-yoga' ), __( 'I guide you in real time. Afterwards the class chat stays open, and a group class can be watched again for a week: only I am on the recording, nobody else.', 'olivia-yoga' ) ),
);
?>
<section class="hero oj-hero" aria-labelledby="hero-title">
	<div class="container hero__grid">
		<div class="hero__text">
			<?php echo oy_kicker( __( 'Online yoga | Olivia Kovacs', 'olivia-yoga' ) ); // phpcs:ignore ?>
			<?php
			// Olívia, 14 November: „hierarchiában ugyanúgy helyezkedjen el a kismamatorna és a mobilizációs órák is” – the three
			// on the same level, each in the colour of its box below, breathing one after the other
			?>
			<h1 class="hero__title oj-hero__title" id="hero-title"><span class="hero__l1"><?php echo esc_html_x( 'Online', 'online hero title, line 1', 'olivia-yoga' ); ?></span> <span class="hero__l2 oj-trio"><mark class="oj-breath oj-breath--lilac"><?php echo esc_html_x( 'yoga', 'online hero title, line 2', 'olivia-yoga' ); ?></mark><span class="sr-only">, </span> <mark class="oj-breath oj-breath--sage"><?php echo esc_html_x( 'prenatal fitness', 'online hero title, line 2', 'olivia-yoga' ); ?></mark><span class="sr-only">, </span> <mark class="oj-breath oj-breath--sun"><?php echo esc_html_x( 'mobility', 'online hero title, line 2', 'olivia-yoga' ); ?></mark></span> <span class="hero__l3"><?php echo esc_html_x( 'live, from home', 'online hero title, line 3', 'olivia-yoga' ); ?></span></h1>
			<p class="hero__lead"><?php esc_html_e( 'Live group online yoga every Wednesday, and individual online yoga, prenatal fitness, stretching, relaxation and breathing tailored to you, from your living room. Beginners very welcome.', 'olivia-yoga' ); ?></p>
			<?php // Olívia, 14 November: „A bejelentkezés és árak maradjon, az óra foglalása gomb jöjjön ki” (the header has it) ?>
			<p class="btn-row oj-hero-cta"><?php echo oy_btn( oy_page_link( 'schedule-pricing' ), oy_schedule_label(), 'forest', 'btn--lg' ); // phpcs:ignore ?></p>
		</div>
		<div class="hero__visual">
			<?php echo oy_soft_blob( 'pink', 'right' ); // phpcs:ignore ?>
			<div class="frame hero__photo"><?php echo oy_photo( 'olivia-golden-backbend', '(min-width: 960px) 52vw, 92vw', true ); // phpcs:ignore ?></div>
			<?php echo oy_name_card( 'hero__card' ); // phpcs:ignore ?>
		</div>
	</div>
</section>

<section class="section section--tight" aria-label="<?php esc_attr_e( 'Ways to practice', 'olivia-yoga' ); ?>"><div class="container"><div class="ways ways--five" data-reveal>
	<?php foreach ( $ways as $w ) : ?>
		<?php
		// Olívia, 14 November: „Az időtartamos jelzéseket az adott szolgáltatás blokkjához illesszük be a főoldalon” – the
		// length from the class's own setting (Classes › Length), so it changes with it
		$oy_class = get_page_by_path( $w[5], OBJECT, 'oy_class' );
		$oy_len   = $oy_class ? (string) oy_class_meta( $oy_class->ID, 'oy_duration' ) : '';
		?>
		<a class="way way--<?php echo esc_attr( $w[4] ); ?>" href="<?php echo esc_url( $w[2] ); ?>"><?php echo oy_way_art( $w[3] ); // phpcs:ignore ?><h3><?php echo esc_html( $w[0] ); ?></h3><p><?php echo esc_html( $w[1] ); ?></p><?php if ( '' !== $oy_len ) : ?><span class="way__len"><?php echo oy_icon( 'clock' ); // phpcs:ignore ?><?php echo esc_html( $oy_len ); ?></span><?php endif; ?><?php if ( 'hatha-flow' === $w[5] && oy_replay_days() ) : ?><span class="way__len way__len--replay"><?php echo oy_icon( 'play' ); // phpcs:ignore ?><?php echo esc_html( oy_replay_label() ); ?></span><?php endif; ?><span class="way__go" aria-hidden="true"><?php echo oy_icon( 'arrow' ); // phpcs:ignore ?></span></a>
	<?php endforeach; ?>
</div></div></section>

<section class="section section--pale" id="why-online" aria-labelledby="why-title"><div class="container">
	<?php echo oy_section_head( __( 'Why practise online?', 'olivia-yoga' ), '', __( 'Why online', 'olivia-yoga' ), 'why-title' ) /* Olívia, 14 November: „Ezt szedjük ki” – the line under the title */; // phpcs:ignore ?>
	<ul class="places places--why" data-reveal><?php foreach ( $why as $n ) : ?><li><?php echo oy_pictogram( $n[0], $n[3] ); // phpcs:ignore ?><div><h3><?php echo esc_html( $n[1] ); ?></h3><p><?php echo esc_html( $n[2] ); ?></p></div></li><?php endforeach; ?></ul>
</div></section>

<section class="section" id="intro" aria-labelledby="intro-title"><div class="container">
	<div class="intro__grid">
		<div class="intro__visual" data-reveal><div class="oj-waved"><?php echo oy_brush_blob( 'lilac', 'left' ); // phpcs:ignore ?><div class="frame"><?php echo oy_photo( 'olivia-golden-hour-arms', '(min-width: 960px) 50vw, 100vw' ); // phpcs:ignore ?></div></div></div>
		<div class="prose" data-reveal>
			<?php
			while ( have_posts() ) {
				the_post();
				echo preg_replace( '/<h2>/', '<h2 id="intro-title">', apply_filters( 'the_content', get_the_content() ), 1 ); // phpcs:ignore
			}
			?>
			<p class="btn-row oj-inline"><?php echo oy_btn( oy_page_link( 'about' ), __( 'Get to know me', 'olivia-yoga' ), 'ghost' ) /* Olívia, 14 November: „itt legyen inkább az, hogy Ismerj meg” */; // phpcs:ignore ?><a class="text-link" href="<?php echo esc_url( oy_page_link( 'yoga-classes' ) ); ?>"><?php esc_html_e( 'See all classes', 'olivia-yoga' ); ?></a></p>
		</div>
	</div>
</div></section>

<section class="section section--pale section--tight oj-contrast" aria-label="<?php esc_attr_e( 'Awareness and control', 'olivia-yoga' ); ?>"><div class="container">
	<figure class="contrast" data-reveal>
		<div><p class="contrast__word"><?php esc_html_e( 'Awareness', 'olivia-yoga' ); ?></p><p>= <?php esc_html_e( 'I notice, I see, I observe, I allow, then I respond', 'olivia-yoga' ); ?></p></div>
		<div class="contrast__col--muted"><p class="contrast__word"><?php esc_html_e( 'Control', 'olivia-yoga' ); ?></p><p>= <?php esc_html_e( 'I want to steer it, I grip it, I regulate it, I push it down', 'olivia-yoga' ); ?></p></div>
		<figcaption><?php esc_html_e( 'To me, this is the most important difference yoga has taught me so far.', 'olivia-yoga' ); ?></figcaption>
	</figure>
</div></section>

<?php
// Olivia, 14 November: „jelenleg több blokk is a csoportos óráról szól a főoldalon. legyen kifejtve kicsit jobban a többi
// szolgáltatás is … a kismamatornára mindenképp erősítsünk rá a főoldalon, megér egy külön blokkot”
$ask      = fn( $topic ) => add_query_arg( 'topic', $topic, oy_private_url() );
// Olívia, 14 November: „A pontokat, hogy mikor ajánlott, fogalmazd át egy kicsit, ne legyen copy … Pipák helyett is találj ki
// mást!” – in our own words, with a small wave from her logo instead of the ticks
$for_whom = array(
	__( 'if you are just starting and want solid foundations', 'olivia-yoga' ),
	__( 'alongside the group classes, to progress faster', 'olivia-yoga' ),
	__( 'if you already practise and would like to learn advanced poses', 'olivia-yoga' ),
	__( 'if meditation and relaxation draw you more, which get less time in a group', 'olivia-yoga' ),
	__( 'in an emotionally harder period, alongside your therapy', 'olivia-yoga' ),
	__( 'if you are coming back to yoga after a long break', 'olivia-yoga' ),
	__( 'as time together with a friend, your sister or brother, or a parent', 'olivia-yoga' ),
	__( 'as a special date with your partner', 'olivia-yoga' ),
	__( 'if a part of your body aches or feels tight and you would like to strengthen or stretch it', 'olivia-yoga' ),
	__( 'as a yoga teacher or a teacher in training, to sit in and gain experience', 'olivia-yoga' ),
	__( 'if the Wednesday group class does not fit your week', 'olivia-yoga' ),
);
$gift_url = function_exists( 'oys_page_url' ) ? oys_page_url( 'gifts' ) : '';
// Olívia, 14 November: „ez a része a blokknak nem annyira tetszik, legyen extrább” – the list becomes a cloud of soft
// bubbles in her four colours, the length and the pair price three small facts, the gift its own voucher
$tones    = array( 'lilac', 'pink', 'sage', 'sun' );
// Olívia, 14 November: „ez a blokk még nem tetszik, hozd be az oldal stílusát … Lehet hogy túl hosszú is ez a felsorolás …
// majd csak az aloldalon kell ezt mind megjeleníteni” – four of them here, as small soft boxes like the rest of the page; the
// whole list is on the one-to-one class page
$fit      = array(
	array( __( 'For beginners', 'olivia-yoga' ), $for_whom[0] ),
	array( __( 'For advanced students', 'olivia-yoga' ), $for_whom[2] ),
	array( __( 'Targeted', 'olivia-yoga' ), $for_whom[8] ),
	array( __( 'Together', 'olivia-yoga' ), __( 'with a friend, your sister or brother, or as a date with your partner – cheaper as a pair', 'olivia-yoga' ) ),
);
$facts    = array(
	array( 'clock', __( '60 or 90 minutes', 'olivia-yoga' ) ),
	array( 'user', __( 'alone or as a pair – cheaper as a pair', 'olivia-yoga' ) ),
	array( 'home', __( 'live, from your living room', 'olivia-yoga' ) ),
);
$modules  = array( __( 'Breathing exercises', 'olivia-yoga' ), __( 'Pelvic floor training', 'olivia-yoga' ), __( 'Better circulation', 'olivia-yoga' ), __( 'Cardio training', 'olivia-yoga' ), __( 'Strength and weight training', 'olivia-yoga' ), __( 'Spine training', 'olivia-yoga' ), __( 'Mobility and stretching', 'olivia-yoga' ), __( 'Yoga', 'olivia-yoga' ), __( 'Relaxation', 'olivia-yoga' ) );
$more     = array(
	array( 'mobility', 'sun', __( 'Online stretching and mobility', 'olivia-yoga' ), __( 'An hour that is only about moving freely again: stretches and mobility work for the hips, shoulders and spine, at your own pace.', 'olivia-yoga' ), array( __( 'if you sit a lot and your hips, shoulders or back feel stiff', 'olivia-yoga' ), __( 'next to running, weights or another sport, for recovery', 'olivia-yoga' ), __( 'if you would like to be more flexible', 'olivia-yoga' ) ), __( 'Cheaper as a pair', 'olivia-yoga' ), 'stretching' ),
	array( 'lotus', 'lilac', __( 'Relaxation and breathing', 'olivia-yoga' ), __( 'Half an hour that is only about calming down: breathing exercises, gentle stretches and guided relaxation for an overloaded nervous system.', 'olivia-yoga' ), array( __( 'if you are stressed and find it hard to switch off', 'olivia-yoga' ), __( 'if you find it hard to fall asleep', 'olivia-yoga' ), __( 'to learn how to calm yourself in a few minutes', 'olivia-yoga' ) ), __( 'Cheaper as a pair', 'olivia-yoga' ), 'relaxacio-legzes' ),
);
?>
<?php // Olívia, 14 November: „az egyéni online jógaórát és a kismamatornát a főoldalon cseréljük meg” – the prenatal block first, the one-to-one block under it, each with its own background as before (the green card would melt into the pale green) ?>
<section class="section section--pale oj-prenatal" id="kismamatorna" aria-labelledby="prenatal-title"><div class="container">
	<div class="oj-split oj-split--flip">
		<div class="oj-split__text" data-reveal>
			<?php echo oy_kicker( __( 'Prenatal fitness', 'olivia-yoga' ) ); // phpcs:ignore ?>
			<h2 id="prenatal-title"><?php esc_html_e( 'Online prenatal fitness: preparing for birth, safely', 'olivia-yoga' ); ?></h2>
			<p class="lead"><?php esc_html_e( 'A one-to-one, 60-minute class, with your friend if you like, planned for your trimester and how you feel that day.', 'olivia-yoga' ); ?></p>
			<p><?php esc_html_e( 'These all come together in the prenatal training: not in one class, but combined, so that you are well prepared:', 'olivia-yoga' ); ?></p>
			<ul class="oj-modules"><?php foreach ( $modules as $m ) : ?><li><?php echo esc_html( $m ); ?></li><?php endforeach; ?></ul>
			<p><?php esc_html_e( 'The first session is an assessment and a conversation: we start from your sports background and how you are now, and we never begin anything more intense than you were used to before.', 'olivia-yoga' ); ?></p>
			<p class="btn-row oj-inline"><?php echo oy_btn( $ask( 'prenatal' ), __( 'Ask for a time', 'olivia-yoga' ), 'orchid' ); // phpcs:ignore ?><a class="text-link" href="<?php echo esc_url( oy_class_url( oy_prenatal_slug() ) ); ?>"><?php esc_html_e( 'More about prenatal fitness', 'olivia-yoga' ); ?></a></p>
		</div>
		<?php // Olívia, 14 November: „a hullám a kép mögött legyen hasonló mint a legelső blokkban” – the soft wave of the hero, not a stroke that runs into the text ?>
		<div class="oj-split__art" data-reveal><div class="oj-waved oj-waved--soft"><?php echo oy_soft_blob( 'pink', 'left' ); // phpcs:ignore ?><div class="frame"><?php echo oy_photo( 'prenatal-ball-training', '(min-width: 960px) 50vw, 100vw' ); // phpcs:ignore ?></div></div></div>
	</div>
</div></section>

<section class="section oj-private" id="egyeni" aria-labelledby="private-title"><div class="container">
	<div class="oj-split">
		<div class="oj-split__text" data-reveal>
			<?php echo oy_kicker( __( 'One-to-one sessions', 'olivia-yoga' ) ); // phpcs:ignore ?>
			<h2 id="private-title"><?php esc_html_e( 'One-to-one online yoga, entirely tailored to you', 'olivia-yoga' ); ?></h2>
			<?php // Olívia, 14 November: „ezt is szedjük ki” – no line between the title and the four boxes ?>
			<ul class="oj-fit"><?php foreach ( $fit as $i => $f ) : ?><li class="oj-fit__item oj-fit__item--<?php echo esc_attr( $tones[ $i % 4 ] ); ?>"><b><?php echo esc_html( $f[0] ); ?></b><span><?php echo esc_html( $f[1] ); ?></span></li><?php endforeach; ?></ul>
			<?php // Olívia, 14 November: „Ez még itt túl katyvaszos, legyen letisztultabb” – one quiet line of facts, the two buttons, and the gift as a small link ?>
			<p class="oj-private__facts"><?php echo esc_html( implode( ' · ', array_map( fn( $f ) => $f[1], $facts ) ) ); ?></p>
			<p class="btn-row oj-inline"><?php echo oy_btn( $ask( 'egyeni-joga' ), __( 'Ask for a time', 'olivia-yoga' ), 'orchid' ); // phpcs:ignore ?><a class="text-link" href="<?php echo esc_url( oy_class_url( 'egyeni-online-joga' ) ); ?>"><?php esc_html_e( 'More about one-to-one yoga', 'olivia-yoga' ); ?></a></p>
			<?php if ( $gift_url ) : ?><p class="oj-private__gift"><?php echo oy_icon( 'gift' ); // phpcs:ignore ?><a href="<?php echo esc_url( $gift_url ); ?>"><?php esc_html_e( 'You can also give it as a gift', 'olivia-yoga' ); ?></a></p><?php endif; ?>
		</div>
		<div class="oj-split__art" data-reveal><div class="oj-waved oj-waved--soft"><?php echo oy_soft_blob( 'lilac', 'right' ); // phpcs:ignore ?><div class="frame"><?php echo oy_photo( 'private-tree-pose', '(min-width: 960px) 50vw, 100vw' ); // phpcs:ignore ?></div></div></div>
	</div>
</div></section>

<?php // Olívia, 14 November: „a stretching, relaxáció és légzést szedjük ki inkább a főoldalról” – they have their own pages ?>

<?php // Olívia, 14 November: „ezt az egész blokkot szedjük ki a szolgáltatásokkal” – the „Online órák” list is gone; the lengths are on the boxes at the top ?>

<section class="section section--pale" id="how" aria-labelledby="how-title"><div class="container private__grid">
	<div data-reveal>
		<?php
		// Olívia, 14 November: „ez a blokk inkább az élő csoportos online jógáról szóljon röviden, kreatívan, ami alatt vannak a
		// következő időpontok, de ugyanígy röviden meg lehet fogalmazni, hogyan működik. a folyamatábrán legyen sima szám”
		echo oy_kicker( __( 'Live group yoga', 'olivia-yoga' ) ); // phpcs:ignore
		?>
		<h2 id="how-title"><?php esc_html_e( 'Wednesday evenings together, everyone from home', 'olivia-yoga' ); ?></h2>
		<p class="lead"><?php esc_html_e( 'You are not following a video: we practise together, live. If you turn your camera on, I can see you and help you with the poses.', 'olivia-yoga' ); ?></p>
		<ul class="places"><?php foreach ( $needs as $n ) : ?><li><?php echo oy_pictogram( $n[0], $n[3] ); // phpcs:ignore ?><div><h3><?php echo esc_html( $n[1] ); ?></h3><p><?php echo esc_html( $n[2] ); ?></p><?php if ( ! empty( $n[4] ) ) : ?><p class="oj-only-me"><?php echo esc_html( $n[4] ); ?></p><?php endif; ?></div></li><?php endforeach; ?></ul>
	</div>
	<div class="private__side" data-reveal>
		<div class="frame"><?php echo oy_photo( 'olivia-dancer-home', '(min-width: 960px) 50vw, 100vw' ); // phpcs:ignore ?></div>
		<h3 class="steps__title"><?php esc_html_e( 'This is how it works', 'olivia-yoga' ); ?></h3>
		<ol class="steps"><?php foreach ( $steps as $s ) : ?><li><div><b><?php echo esc_html( $s[0] ); ?></b><span><?php echo esc_html( $s[1] ); ?></span></div></li><?php endforeach; ?></ol>
		<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid' ) . oy_btn( oy_page_link( 'faq' ), __( 'Questions about online classes', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
	</div>
</div></section>

<section class="section section--tight" id="schedule" aria-labelledby="schedule-title"><div class="container">
	<header class="section__head section__head--row" data-reveal><div><?php echo oy_kicker( __( 'Next classes', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="schedule-title"><?php esc_html_e( 'Weekly live classes', 'olivia-yoga' ); ?></h2><?php // Olívia, 14 November: „ezt is kiszedhetjük” – no line under the title ?></div><?php echo oy_btn( oy_page_link( 'schedule-pricing' ), oy_schedule_label(), 'ghost' ); // phpcs:ignore ?></header>
	<?php // Olivia, 13 November: only the Wednesdays (the next ones, however far), and the one-to-one sessions in a box of their own; 14 November: „itt mindig a következő 3 óra legyen látható” ?>
	<?php echo shortcode_exists( 'oys_schedule' ) ? do_shortcode( '[oys_schedule days="7" next="3" empty_days="no"]' ) . oy_replay_note() . ( shortcode_exists( 'oys_private_box' ) ? do_shortcode( '[oys_private_box]' ) : '' ) . oy_cancel_note() : ''; // phpcs:ignore ?>
</div></section>

<section class="section section--moss hatha" aria-labelledby="hatha-title"><div class="container hatha__grid">
	<div data-reveal><div class="hatha__pair"><div class="hatha__orb hatha__orb--sun"><span>Ha</span></div><div class="hatha__orb hatha__orb--moon"><span>Tha</span></div><span class="hatha__word" aria-hidden="true">Hatha</span></div><div class="hatha__caption"><span><?php esc_html_e( 'sun: effort, heat, strength', 'olivia-yoga' ); ?></span><span><?php esc_html_e( 'moon: ease, cool, surrender', 'olivia-yoga' ); ?></span></div></div>
	<div data-reveal>
		<?php echo oy_kicker( __( 'What hatha means', 'olivia-yoga' ) ); // phpcs:ignore ?>
		<h2 id="hatha-title"><?php esc_html_e( 'Ha is the sun. Tha is the moon.', 'olivia-yoga' ); ?></h2>
		<p class="lead"><?php esc_html_e( 'Hatha yoga balances the active and the receptive in us: effort and ease, strength and surrender. When that balance tips, we feel it first as tension or restlessness.', 'olivia-yoga' ); ?></p>
		<p><?php esc_html_e( 'On the mat we use the body to find the balance again. The poses are a doorway to meditation, a more dynamic doorway, and only one of the eight limbs of yoga.', 'olivia-yoga' ); ?></p>
		<?php // Olivia, 13 November: to „Jógáról”, straight down to the eight limbs of yoga ?>
		<p class="btn-row"><?php echo oy_btn( oy_limbs_url(), __( 'Read more about hatha yoga', 'olivia-yoga' ), 'line-light' ); // phpcs:ignore ?></p>
	</div>
</div></section>

<?php // Olívia, 14 November: „a lótuszos légzésnek legyen inkább ez a szín a háttere” – peach ?>
<section class="section section--breath oj-breath-peach" aria-label="<?php esc_attr_e( 'Breathing exercise', 'olivia-yoga' ); ?>"><div class="container"><?php echo oy_breath(); // phpcs:ignore ?></div></section>

<section class="section section--tight" id="pricing" aria-labelledby="pricing-title"><div class="container">
	<?php // Olivia, 13 November: „Az »árak forintban«-t szedjük ki. A főcím legyen csak árak” ?>
	<?php echo oy_section_head( __( 'Prices', 'olivia-yoga' ), __( 'Pay per class, save with a 5-class pass, or give someone a gift card.', 'olivia-yoga' ), '', 'pricing-title' ); // phpcs:ignore ?>
	<?php echo $studio( 'oys_pricing' ); // phpcs:ignore ?>
</div></section>

<section class="section" id="faq" aria-labelledby="faq-title"><div class="container faq-grid">
	<header class="section__head" data-reveal><?php echo oy_kicker( __( 'FAQ', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="faq-title"><?php esc_html_e( 'Got a question?', 'olivia-yoga' ) /* Olívia, 14 November: „ez kicsit erőltetett, ne ez legyen a fő kérdés” */; ?></h2><p><a class="text-link" href="<?php echo esc_url( oy_page_link( 'faq' ) ); ?>"><?php esc_html_e( 'All questions', 'olivia-yoga' ); ?></a></p></header>
	<?php echo oy_faq_list( true ); // phpcs:ignore ?>
</div></section>

<?php $journal = get_posts( array( 'numberposts' => 3 ) ); ?>
<?php if ( $journal ) : ?>
<section class="section section--pale" aria-labelledby="journal-title"><div class="container">
	<header class="section__head section__head--row" data-reveal><div><?php echo oy_kicker( __( 'About yoga', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="journal-title"><?php esc_html_e( 'Read more about yoga', 'olivia-yoga' ); ?></h2></div><?php echo oy_btn( oy_page_link( 'journal' ), __( 'All articles', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></header>
	<?php get_template_part( 'template-parts/cards', null, array( 'posts' => $journal ) ); ?>
</div></section>
<?php endif; ?>

<section class="section cta oj-cta--peach" aria-labelledby="cta-title"><div class="container"><div class="cta__inner" data-reveal>
	<h2 id="cta-title"><?php esc_html_e( 'Your mat is waiting', 'olivia-yoga' ); ?></h2>
	<p class="lead"><?php esc_html_e( 'Join a live online class, ask for a one-to-one session, or just send a question. Beginners very welcome.', 'olivia-yoga' ); ?></p>
	<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid', 'btn--lg' ) . oy_btn( oy_contact_url(), __( 'Send a message', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
</div></div></section>
