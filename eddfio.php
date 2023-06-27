<?php
/*
Plugin Name: Easy Digital Downloads - FIO převod
Plugin URL: https://cleverstart.cz
Description: Umožní automatické provedení platby, kde se zákazníkovi ukáže číslo účtu, na které má zaplatit. Po zaplacení se platba spáruje
Version: 2.2.93
Author: Pavel Janíček
Author URI: https://cleverstart.cz
*/
require_once(plugin_dir_path(__FILE__) . 'vendor/autoload.php');

$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
	'https://plugins.cleverstart.cz/?action=get_metadata&slug=eddfio',
	__FILE__, //Full path to the main plugin file or functions.php.
	'eddfio'
);


// registers the gateway
function eddfio_register_gateway( $gateways ) {
	//$gateways['eddfio'] = array( 'admin_label' => 'FIO převod', 'checkout_label' => __( 'Platba převodem (Fio banka)', 'eddprevod' ) );
	$gateways['eddfio'] = array( 'admin_label' => 'FIO převod', 'checkout_label' => __( 'Bankovní převod (1-2 dny)', 'eddprevod' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'eddfio_register_gateway' );



/* Runs on plugin deactivation*/
register_deactivation_hook( __FILE__, 'eddfio_uninstall' );

function eddfio_uninstall(){
	wp_clear_scheduled_hook('eddfio_hourly');
}

function eddfio_activate(){
	 if (! wp_next_scheduled ( 'eddfio_hourly' )) {
 	 		wp_schedule_event(time(), 'hourly', 'eddfio_hourly');

	 }

}
register_activation_hook( __FILE__, 'eddfio_activate' );
add_action('eddfio_hourly', 'eddfio_create_haystack');


function edd_eddfio_cc_form() {
	return;
}
add_action('edd_eddfio_cc_form', 'edd_eddfio_cc_form');

function eddfio_listener_url($payment_id){

	return get_home_url() . "?edd-listener=eddfio&vs=" .$payment_id;
}


// processes the payment
function eddfio_process_payment( $purchase_data ) {

	global $edd_options;

	/**********************************
	* set transaction mode
	**********************************/

	/*if ( edd_is_test_mode() ) {
		// set test credentials here
	} else {
		// set live credentials here
	}*/





	/**********************************
	* Purchase data comes in like this:

    $purchase_data = array(
        'downloads'     => array of download IDs,
        'tax' 			=> taxed amount on shopping cart
        'fees' 			=> array of arbitrary cart fees
        'discount' 		=> discounted amount, if any
        'subtotal'		=> total price before tax
        'price'         => total price of cart contents after taxes,
        'purchase_key'  =>  // Random key
        'user_email'    => $user_email,
        'date'          => date( 'Y-m-d H:i:s' ),
        'user_id'       => $user_id,
        'post_data'     => $_POST,
        'user_info'     => array of user's information and used discount code
        'cart_details'  => array of cart details,
     );
    */



		$purchase_summary = edd_get_purchase_summary( $purchase_data );

		/****************************************
		* setup the payment details to be stored
		****************************************/

		$payment_data = array(
			'price'        => $purchase_data['price'],
			'date'         => $purchase_data['date'],
			'user_email'   => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency'     => $edd_options['currency'],
			'downloads'    => $purchase_data['downloads'],
			'cart_details' => $purchase_data['cart_details'],
			'user_info'    => $purchase_data['user_info'],
			'status'       => 'pending'
		);

		// record the pending payment
		$payment = edd_insert_payment( $purchase_data );




		$to = $payment_data['user_email'];
		$subject = edd_get_option('eddfio_user_mail_subject', 'Děkujeme za nákup!');
		$subject = apply_filters( 'eddfio_user_mail_subject', wp_strip_all_tags( $subject ), $payment );
		$subject = edd_do_email_tags( $subject, $payment );
		$message = edd_get_option( 'eddfio_user_mail_text', edd_get_default_eddfio_user_notification_email() );
		$message = edd_do_email_tags( $message, $payment);
		EDD()->emails->send( $to, $subject, $message );

		$after_purchase_notify = (isset($edd_options['eddfio_po_nakupu'])) AND (!empty($edd_options['eddfio_po_nakupu']));
		if (!$after_purchase_notify){
			$admin_to = eddfio_get_admin_notice_emails();
			$admin_subject = edd_get_option('eddfio_admin_mail_subject', 'Nová objednávka #{payment_id}');
			$admin_subject = apply_filters( 'eddfio_admin_mail_subject', wp_strip_all_tags( $admin_subject ), $payment );
			$admin_subject = edd_do_email_tags( $admin_subject, $payment );
			$admin_message = edd_get_option( 'eddfio_admin_mail_text', edd_get_default_eddfio_admin_notification_email() );
			$admin_message = edd_do_email_tags( $admin_message, $payment);
			EDD()->emails->send( $admin_to, $admin_subject, $admin_message );
		}


		$url = eddfio_listener_url($payment);


		edd_empty_cart();
		header('Location: ' . $url);

}
add_action( 'edd_gateway_eddfio', 'eddfio_process_payment' );



//process pingback
function eddfio_pingback() {
   global $edd_options;
   $variabilni = $_GET['vs'];
   $payment_meta = get_post_meta( $variabilni, '_edd_payment_meta', true );
	 $payment = new EDD_Payment( $variabilni );
	 $payment_amount 	= round($payment->total,2);
	 $currency = $payment_meta['currency'];
	 



	 $message .="Děkujeme za nákup. Níže posíláme údaje k platbě:\\n";
		$message .="Číslo účtu: " . $edd_options['eddfio_account_number']."\\n";
		$message .="Kód banky: " .$edd_options['eddfio_bank_number']. "\\n";
		if (isset($edd_options['eddfio_euro_account_number']) and !empty($edd_options['eddfio_euro_account_number'])){
				$message .="Číslo eurového účtu: " . $edd_options['eddfio_euro_account_number']."\\n";
		}
	  if (isset($edd_options['eddfio_euro_bank_number']) and !empty($edd_options['eddfio_euro_bank_number'])){
			$message .="Kód banky eurového účtu: " .$edd_options['eddfio_euro_bank_number']. "\\n";
		}
		if (isset($edd_options['eddfio_iban_number']) and !empty($edd_options['eddfio_iban_number'])){
			$message .="Číslo IBAN: " .$edd_options['eddfio_iban_number']. "\\n";
		}
		if (isset($edd_options['eddfio_swift_number']) and !empty($edd_options['eddfio_swift_number'])){
			$message .="Kód SWIFT: " .$edd_options['eddfio_swift_number']. "\\n";
		}
		$message .="Variabilní symbol: ". $payment->number ."\\n";
		$message .="Celková částka: ".$payment_amount." ". $currency."\\n";
		$message .="Údaje jsme vám taktéž poslali e-mailem.";

  // The old way - keep as fallback
    echo "<script>";
    echo "alert(\"" .$message. "\");";
		$dekovacka = (isset($edd_options['eddfio_nedekuj'])) AND (!empty($edd_options['eddfio_nedekuj']));
		if (isset($edd_options['eddfio_dekovaci_stranka']) AND !$dekovacka){
	  	echo "window.location=\"" .get_permalink($edd_options['eddfio_dekovaci_stranka']). "\"";
		}
    echo "</script>";
  // dump_data();
}

add_action( 'eddfio_pingback', 'eddfio_pingback' );


// listen for pingback
function edd_listen_for_eddfio_pingback() {
	global $edd_options;

	// Regular GoPay IPN
	if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'eddfio' ) {
		do_action( 'eddfio_pingback' );
	}
    if (isset($_GET['listener']) and ($_GET['listener'] == 'woofio') ){
        do_action('eddfio_debug');
    }
}
add_action( 'init', 'edd_listen_for_eddfio_pingback' );


// adds the settings to the Payment Gateways section
function eddfio_add_settings( $settings ) {

	$sample_gateway_settings = array(
		array(
			'id' => 'sample_gateway_settings',
			'name' => '<strong>' . __( 'Nastavení bankovního převodu', 'prevod' ) . '</strong>',
			'desc' => __( 'Nastavení bankovního převodu', 'prevod' ),
			'type' => 'header'
		),
		array(
			'id' => 'eddfio_account_number',
			'name' => __( 'Číslo účtu', 'prevod' ),
			'desc' => __( 'Uveďte prosím číslo účtu, na který budou zákazníci platit převodem', 'prevod' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddfio_bank_number',
			'name' => __( 'Kód banky', 'prevod' ),
			'desc' => __( 'Uveďte prosím kód banky, na který budou zákazníci platit převodem', 'prevod' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddfio_iban_number',
			'name' => __( 'Číslo IBAN', 'prevod' ),
			'desc' => __( 'Uveďte prosím IBAN, na který budou zákazníci platit převodem ze zahraničí', 'prevod' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddfio_swift_number',
			'name' => __( 'Kód SWIFT', 'prevod' ),
			'desc' => __( 'Uveďte prosím kód SWIFT, na který budou zákazníci platit převodem ze zahraničí', 'prevod' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddfio_euro_account_number',
			'name' => __( 'Číslo  eurového účtu', 'prevod' ),
			'desc' => __( 'Uveďte prosím číslo eurového účtu, pokud máte založený', 'prevod' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddfio_euro_bank_number',
			'name' => __( 'Kód banky eurového účtu', 'prevod' ),
			'desc' => __( 'Uveďte prosím kód banky eurového účtu', 'prevod' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddfio_euro_iban_number',
			'name' => __( 'Číslo IBAN eurového účtu', 'prevod' ),
			'desc' => __( 'Uveďte prosím číslo IBAN eurového účtu', 'prevod' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddfio_euro_swift_number',
			'name' => __( 'Kód SWIFT eurového účtu', 'prevod' ),
			'desc' => __( 'Uveďte prosím kód SWIFT eurového účtu', 'prevod' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id'	=> 'eddfio_token',
			'name' => 'FIO API Token',
			'desc' => 'Vložte vygenerovaný token z FIO bankovnictví (Nastavení - API. Přidat nový token, práva Pouze sledování účtu, neomezená platnost)',
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'eddfio_dekovaci_stranka',
			'name' => 'Děkovací stránka',
			'desc' => 'Vyberte stránku na kterou bude uživatel přesměrován po stisku tlačítka Koupit',
			'type' => 'select',
			'options' => eddfio_all_pages()
		),
		array(
			'id' => 'eddfio_nedekuj',
			'name' => 'Nezobrazovat děkovací stránku',
			'desc' => 'Po stisku tlačítka Koupit budou zákazníci přesměrováni na hlavní stránku',
			'type' => 'checkbox'
		),
		array(
			'id' => 'eddfio_po_nakupu',
			'name' => 'Nezasílat adminovi e-mail po nákupu',
			'desc' => 'Plugin vás bude upozorňovat na každý nákup. Pokud toto zaškrtnete, tak připomenutí nebudou odcházet',
			'type' => 'checkbox'
		)
	);

	return array_merge( $settings, $sample_gateway_settings );
}
add_filter( 'edd_settings_gateways', 'eddfio_add_settings' );

function eddfio_debug(){
	require_once(plugin_dir_path(__FILE__) . 'vendor/autoload.php');
		  global $edd_options;
		$pending_orders = eddfio_pending_variables();
		echo "<p>pending orders <br /></p>";
		print_r($pending_orders);

		echo "<p>all transactions from fio<br /></p>";
		$downloader = new FioApi\Downloader($edd_options['eddfio_token']);
		//$downloader->setCertificatePath(__DIR__ . '/vendor/mhujer/fio-api-php/src/FioApi/keys/cacert.pem');
		echo "<p>Current certificate used</p>";
		echo $downloader->getCertificatePath();
		echo "<br>";
		$success = false;
		try{
			$transactionList = $downloader->downloadSince(new \DateTime('-1 week'));
			$allTransactions = $transactionList->getTransactions();
			foreach ($allTransactions as $transaction) {
				if ($transaction->getAmount()>0){
					echo "<p>Variable: " . $transaction->getVariableSymbol() ."<br /></p>";
					echo "<p>Amount: " . $transaction->getAmount() . "<br/></p>";
					echo "<p> currency: " . $transaction->getCurrency() . "<br/></p>";

				}
			}

		}catch (Exception $e){
			echo $e->getMessage();
		}
		echo "<p>array column</p>";
		print_r(array_column($pending_orders, 'number'));
		echo "Payment";
		$payment_id = 168975;
		if (isset($_GET['id'])){
			$payment_id = $_GET['id'];
		}
		$payment = new EDD_Payment($payment_id);
		print_r($payment);
		exit;

}





function eddfio_create_haystack(){
	require_once(plugin_dir_path(__FILE__) . 'vendor/autoload.php');
	$date = date("Y.m.d H:i");
	global $edd_options;
	if(!isset($edd_options['eddfio_token'])){

		return;
	}

	$pending_variables = eddfio_pending_variables();
	if (empty($pending_variables)){
		return;
	}
	eddfio_debug_to_console($date .' hook fired');
	try{
	$downloader = new FioApi\Downloader($edd_options['eddfio_token']);
	//$downloader->setCertificatePath(__DIR__ . '/vendor/mhujer/fio-api-php/src/FioApi/keys/cacert.pem');
	$transactionList = $downloader->downloadSince(new \DateTime('-1 week'));
	  $allTransactions = $transactionList->getTransactions();
	  eddfio_debug_to_console('all transactions');
	}catch (Exception $e){
	  eddfio_debug_to_console('exception na create haystack');
	  eddfio_debug_to_console($e->getMessage());
	  return null;
    }
	  //eddfio_debug_to_console($allTransactions);
	foreach ($allTransactions as $transaction) {
		if ($transaction->getAmount()>0){
			if (in_array($transaction->getVariableSymbol(),$pending_variables)){
				eddfio_debug_to_console('checking payment '. $transaction->getVariableSymbol());
				eddfio_debug_to_console('AMOUNT: ' .$transaction->getAmount(). ' currency: ' .$transaction->getCurrency());
				$payment_id = array_search($transaction->getVariableSymbol(),$pending_variables);
				eddfio_maybe_complete_payment($payment_id,$transaction->getAmount(),$transaction->getCurrency());
			}
		}
	}
}

function eddfio_debug_to_console( $data) {
	$output = $data;
	$logforreal = true;
	if ( is_array( $output ) ){
		$output = implode(', ', array_map(
			function ($v, $k) {
				if (is_object($v)){
					$v = serialize($v);
				}
				return sprintf("%s='%s'", $k, $v);
			 },
			$output,
			array_keys($output)
		));
	}
	if (is_object($output)){
		$output = serialize($output);
	}
	if ($logforreal){
		error_log($output ."\n", 3, 'eddfio.log');
	}

}



function eddfio_maybe_complete_payment($variable,$paid_amount,$currency){
	global $edd_options;

	$payment = new EDD_Payment( $variable );
	eddfio_debug_to_console('edd payment amount: ' .$payment->total. ' currency: ' .$payment->currency);
  if (($payment->total == $paid_amount) and ($currency == $payment->currency)){

		edd_update_payment_status( $variable, 'publish' );
	}
}





function eddfio_pending_variables(){
	$query = array(
	'post_type' => 'edd_payment',
	'post_status' => 'pending',
	'posts_per_page'=>-1
	);
	$pending_payments = new WP_Query($query);

	$found_variables = array();
	$i = 0;
	while ($pending_payments->have_posts()){
		$pending_payments->the_post();
		$payment = new EDD_Payment($pending_payments->post->ID);
		$found_variables[$pending_payments->post->ID] = $payment->number;
		$i++;
	}
	return $found_variables;
}

function eddfio_all_pages(){
	$pages = get_pages();
	$allPages = array();
  foreach ( $pages as $page ) {
		$allPages[$page->ID] = $page->post_title;
	}
	return $allPages;
}



function add_all_email_tags($payment_id){
	edd_add_email_tag( 'VS', 'Variabilní Symbol', 'eddfio_edd_email_tag_vs' );
	edd_add_email_tag( 'ucet', 'Číslo účtu', 'eddfio_edd_email_tag_ucet' );
	edd_add_email_tag( 'kod_banky', 'Kód banky', 'eddfio_edd_email_tag_banka' );
	edd_add_email_tag( 'IBAN', 'Číslo IBAN', 'eddfio_edd_email_tag_iban' );
	edd_add_email_tag( 'SWIFT', 'Kód SWIFT', 'eddfio_edd_email_tag_swift' );
	edd_add_email_tag( 'euro_ucet', 'Číslo eurového účtu', 'eddfio_edd_email_tag_euro_ucet');
	edd_add_email_tag( 'kod_euro_banky', 'Kód banky eurového účtu', 'eddfio_edd_email_tag_euro_banka');
	edd_add_email_tag( 'IBAN_EUR', 'Číslo IBAN eurového účtu', 'eddfio_edd_email_tag_iban_eur' );
	edd_add_email_tag( 'SWIFT_EUR', 'Kód SWIFT eurového účtu', 'eddfio_edd_email_tag_swift_eur' );
	edd_add_email_tag( 'celkem_s_menou', 'Celková částka, včetně měny', 'eddfio_edd_email_tag_celkem_s_menou' );

}

add_action( 'edd_add_email_tags', 'add_all_email_tags' );

function eddfio_settings_section( $sections ) {
	$sections['eddfio-mail'] = __( 'E-Mailové notifikace po nákupu přes FIO', 'eddfio' );
	return $sections;
}
add_filter( 'edd_settings_sections_emails', 'eddfio_settings_section' );

function eddfio_email_settings($settings){
	$pavel_settings = array(
	          array(
	            'id'   => 'eddfio_email_settings',
	            'name' => '<strong>' . __( 'Nastavení notifikačních mailů při nákupu přes FIO bránu', 'eddfio' ) . '</strong>',
	            'desc' => __( 'Nastavte znění mailů', 'eddpdfi' ),
	            'type' => 'header'
	          ),
						array(
							'id'   => 'eddfio_admin_mail_subject',
							'name' => __( 'Předmět mailu pro admina', 'eddfio' ),
							'desc' => __( 'Uveďte předmět zprávy, notifikace pro administrátora stránek', 'easy-digital-downloads' ),
							'type' => 'text',
							'std'  => 'Nová objednávka #{payment_id}'
						),
						array(
							'id'   => 'eddfio_admin_mail_text',
							'name' => __( 'Text mailu pro admina', 'easy-digital-downloads' ),
							'desc' => __( 'Uveďte zprávu, kterou obdrží admin stránek. Dostupné tagy:', 'easy-digital-downloads' ) . '<br/>' . edd_get_emails_tags_list(),
							'type' => 'rich_editor',
							'std'  => edd_get_default_eddfio_admin_notification_email()
						),
						array(
							'id'   => 'eddfio_user_mail_subject',
							'name' => __( 'Předmět mailu pro uživatele', 'eddfio' ),
							'desc' => __( 'Uveďte předmět zprávy, notifikace pro kupujícího', 'easy-digital-downloads' ),
							'type' => 'text',
							'std'  => 'Děkujeme za nákup!'
						),
						array(
							'id'   => 'eddfio_user_mail_text',
							'name' => __( 'Text mailu pro uživatele', 'easy-digital-downloads' ),
							'desc' => __( 'Uveďte zprávu, kterou obdrží zájemce o koupi. Dostupné tagy:', 'easy-digital-downloads' ) . '<br/>' . edd_get_emails_tags_list(),
							'type' => 'rich_editor',
							'std'  => edd_get_default_eddfio_user_notification_email()
						),


	        );
	        if ( version_compare( EDD_VERSION, 2.5, '>=' ) ) {
	          $pavel_settings = array( 'eddfio-mail' => $pavel_settings );
	        }

	return array_merge( $settings, $pavel_settings );
	}

	add_filter( 'edd_settings_emails', 'eddfio_email_settings' );



function eddfio_edd_email_tag_vs( $payment_id ) {
	$payment = new EDD_Payment($payment_id);
	return $payment->number;
}

function eddfio_edd_email_tag_ucet( $payment_id ) {
	global $edd_options;
	return $edd_options['eddfio_account_number'];
}

function eddfio_edd_email_tag_euro_ucet( $payment_id ) {
	global $edd_options;
	return $edd_options['eddfio_euro_account_number'];
}

function eddfio_edd_email_tag_banka( $payment_id ) {
	global $edd_options;
	return $edd_options['eddfio_bank_number'];
}

function eddfio_edd_email_tag_euro_banka( $payment_id ) {
	global $edd_options;
	return $edd_options['eddfio_euro_bank_number'];
}



function eddfio_edd_email_tag_iban( $payment_id ) {
	global $edd_options;
	return $edd_options['eddfio_iban_number'];
}

function eddfio_edd_email_tag_iban_eur( $payment_id ) {
	global $edd_options;
	return $edd_options['eddfio_euro_iban_number'];
}

function eddfio_edd_email_tag_swift( $payment_id ) {
	global $edd_options;
	return $edd_options['eddfio_swift_number'];
}

function eddfio_edd_email_tag_swift_eur( $payment_id ) {
	global $edd_options;
	return $edd_options['eddfio_euro_swift_number'];
}

function eddfio_edd_email_tag_celkem_s_menou($payment_id){
	$payment = new EDD_Payment($payment_id);
	$payment_amount 	= round($payment->total,2);
	$payment_meta = get_post_meta( $payment_id, '_edd_payment_meta', true );
	$currency = $payment_meta['currency'];
	return $payment_amount." ". $currency;
}

function edd_get_default_eddfio_admin_notification_email() {
 $default_email_body = "Nová objednávka na platbu převodem" . "\n\n" . sprintf( __( 'A %s purchase has been made', 'easy-digital-downloads' ), edd_get_label_plural() ) . ".\n\n";
 $default_email_body .= sprintf( __( '%s sold:', 'easy-digital-downloads' ), edd_get_label_plural() ) . "\n\n";
 $default_email_body .= '{download_list}' . "\n\n";
 $default_email_body .= __( 'Purchased by: ', 'easy-digital-downloads' ) . ' {name}' . "\n";
 $default_email_body .= __( 'Amount: ', 'easy-digital-downloads' ) . '{price}' . "\n";
 $default_email_body .= __( 'Payment Method: ', 'easy-digital-downloads' ) . ' {payment_method}' . "\n\n";
 $default_email_body .= __( 'Thank you', 'easy-digital-downloads' );
 $message = edd_get_option( 'eddfio_admin_mail_text', false );
 $message = ! empty( $message ) ? $message : $default_email_body;
 return $message;
}

function edd_get_default_eddfio_user_notification_email() {
	$default_email_body = "<h1>Děkujeme vám za nákup</h1>";
	$default_email_body .="<p>Níže posíláme údaje k platbě:</p>";
	$default_email_body .="<p><strong>Číslo účtu: </strong> {ucet} </p>";
	$default_email_body .="<p><strong>Kód banky: </strong> {kod_banky} </p>";
	$default_email_body .="<p><strong>Číslo IBAN: </strong> {IBAN} </p>";
	$default_email_body .="<p><strong>Kód SWIFT: </strong> {SWIFT} </p>";
	$default_email_body .="<p><strong>Variabilní symbol: </strong> {VS} </p>";
	$default_email_body .="<p><strong>Celková částka: </strong> {price} </p>";
	$default_email_body .="<p>Jakmile se platba spáruje, zašleme vám odkaz na stažení vámi zvoleného produktu/produktů a doklad o nákupu.</p>";
	$message = edd_get_option( 'eddfio_user_mail_text', false );
	$message = ! empty( $message ) ? $message : $default_email_body;
  return $message;
}

function eddfio_get_admin_notice_emails() {

 	global $edd_options;

 	$emails = isset( $edd_options['admin_notice_emails'] ) && strlen( trim( $edd_options['admin_notice_emails'] ) ) > 0 ? $edd_options['admin_notice_emails'] : get_bloginfo( 'admin_email' );
 	$emails = array_map( 'trim', explode( "\n", $emails ) );

 	return apply_filters( 'edd_admin_notice_emails', $emails );
 }



 add_action('eddfio_debug', 'eddfio_debug');
