<?php
defined( 'ABSPATH' ) or die( 'Please!' );
$params = isset($_POST['params']) ? $_POST['params'] : '';
$nonce = isset($params['ds-nonce']) ? $params['ds-nonce'] : '';

if (! wp_verify_nonce($nonce, 'direct-stripe-nonce') ) die( __('Security check issue', 'direct-stripe') );

// Stripe
if( ! class_exists( 'Stripe\Stripe' ) ) {
    require_once(DSCORE_PATH . '/stripe/init.php');
}
$d_stripe_general = get_option( 'direct_stripe_general_settings' );
$d_stripe_emails = get_option( 'direct_stripe_emails_settings' );
$headers =  array('Content-Type: text/html; charset=UTF-8');

// API keys
if( isset($d_stripe_general['direct_stripe_checkbox_api_keys']) && $d_stripe_general['direct_stripe_checkbox_api_keys'] === '1' ) {
\Stripe\Stripe::setApiKey($d_stripe_general['direct_stripe_test_secret_api_key']);
} else {
\Stripe\Stripe::setApiKey($d_stripe_general['direct_stripe_secret_api_key']);
}

try{ //Retrieve Data
$button_id 	    = isset($params['button_id']) ? $params['button_id'] : '';
$pre_amount     = isset($_POST['amount']) ? $_POST['amount'] : '';
$amount 	    = $pre_amount * 100;
$token 		    = $_POST['stripeToken'];
$email_address  = $_POST['stripeEmail'];
$admin_email    = get_option( 'admin_email' );
if( $params['capture'] === 'false' ) {
	$capture =  false;
} else {
	$capture =  true;
}
$description    = isset($params['description']) ? $params['description'] : '';

$custom_role    = isset($params['custom_role']) ? $params['custom_role'] : '';
	if ( !empty( $custom_role  ) && wp_roles()->is_role( $custom_role ) == false ) {
		add_role( $custom_role  , __('Direct Stripe ' . $custom_role , 'direct-stripe'), array( 'read' => true ));
	}
	
if( !empty($params['currency']) ) {
	$currency = $params['currency'];
} else {
	$currency = $d_stripe_general['direct_stripe_currency'];
}

//Check if user exists
if( username_exists( $email_address ) || email_exists( $email_address ) ) { 
	//User exists, check if it already have a stripe ID
	$user = get_user_by( 'email', $email_address );
	$stripe_id_array = get_user_meta( $user->id, 'stripe_id', true );
    $user_id = $user->id;
		if ( isset($stripe_id_array) && !empty($stripe_id_array) ) {//Stripe ID exists
			//Retrieve Stripe ID and update user roles
			$stripe_id = $stripe_id_array; //implode(" ", $stripe_id_array);
			$user->add_role( 'stripe-user' );
			$user->add_role( $custom_role );
		}
		else {//It doesn't have Stripe ID
			//Create Stripe Customer
			$customer = \Stripe\Customer::create(array(
				'email'     => $email_address,
				'source'    => $token
			));
			$stripe_id = $customer->id;
			//Update User roles
			update_user_meta($user_id, 'stripe_id', $stripe_id);
			$user->add_role( 'stripe-user' );
			$user->add_role( $custom_role );
		}
	
} else { // User doesn't exist	
	$stripe_id == false;
}

if($stripe_id) { // User exists
//Charge
	$charge = \Stripe\Charge::create(array(
		'customer'      => $stripe_id,
		'amount'        => $amount,
		'currency'      => $currency,
		'capture'       => $capture,
		'description'   => $description
	));
	
//Log transaction in WordPress admin
	$post_id = wp_insert_post(
		array(
			'post_title' 	=> $token,
			'post_status' 	=> 'publish',
			'post_type' 	=> 'Direct Stripe Logs',
			'post_author'	=>  $user_id
		)
	);
	add_post_meta($post_id, 'amount', $amount);
	add_post_meta($post_id, 'type', __('donation','direct-stripe') );
	add_post_meta($post_id, 'description', $description );
	
} else { // Utilisateur non reconnu
//Stripe customer	
  $customer = \Stripe\Customer::create(array(
        'email'     => $email_address,
        'source'    => $token
  ));
//Create Charge
  $charge = \Stripe\Charge::create(array(
        'customer'      => $customer->id,
        'amount'        => $amount,
        'currency'      => $currency,
		'capture'       => $capture,
		'description'   => $description
  ));
	
// Generate the password and create the user
  $password = wp_generate_password( 12, false );
//Create User
  $user_id = wp_create_user( $email_address, $password, $email_address );
// Set the nickname
  wp_update_user(
    array(
      'ID'          =>    $user_id,
      'nickname'    =>    $email_address
    )
  );
//Add Stripe ID and User roles
	update_user_meta($user_id, 'stripe_id', $customer->id );
	$user = new WP_User( $user_id );
	$user->add_role( 'stripe-user' );
	$user->add_role( $custom_role );
	
//Log transaction in WordPress admin
  $post_id = wp_insert_post(
		array(
			'post_title' => $token,
			'post_status' => 'publish',
			'post_type' => 'Direct Stripe Logs',
			'post_author' =>	$user_id
		)
	);
	add_post_meta($post_id, 'amount', $amount);
	add_post_meta($post_id, 'type', __('donation','direct-stripe') );
	add_post_meta($post_id, 'description', $description );

	
}// Fin if else
	
	// Email client
    if(  isset($d_stripe_emails['direct_stripe_user_emails_checkbox'])  && $d_stripe_emails['direct_stripe_user_emails_checkbox'] === '1' ) {

        $email_subject = apply_filters( 'direct_stripe_success_user_email_subject', $d_stripe_emails['direct_stripe_user_email_subject'], $token, $amount, $currency, $email_address, $description, $user_id, $button_id );
        $email_content = apply_filters( 'direct_stripe_success_user_email_content', $d_stripe_emails['direct_stripe_user_email_content'], $token, $amount, $currency, $email_address, $description, $user_id, $button_id );

        wp_mail( $email_address, $email_subject , $email_content, $headers );
    }
// Email admin
    if(  isset($d_stripe_emails['direct_stripe_admin_emails_checkbox'])  && $d_stripe_emails['direct_stripe_admin_emails_checkbox'] === '1' ) {

        $email_subject = apply_filters( 'direct_stripe_success_admin_email_subject', $d_stripe_emails['direct_stripe_admin_email_subject'], $token, $amount, $currency, $email_address, $description, $user_id, $button_id );
        $email_content = apply_filters( 'direct_stripe_success_admin_email_content', $d_stripe_emails['direct_stripe_admin_email_content'], $token, $amount, $currency, $email_address, $description, $user_id, $button_id );

        wp_mail( $admin_address, $email_subject , $email_content, $headers );
    }
	
// Add custom action before redirection
	$chargeID = $charge->id;
	do_action( 'direct_stripe_before_success_redirection', $chargeID, $post_id, $button_id, $user_id );

    //Answer for ajax
    if( isset($d_stripe_general['direct_stripe_use_redirections'])  && $d_stripe_general['direct_stripe_use_redirections'] === '1' ) {

        $success_url	= isset($params['success_url']) ? $params['success_url'] : '';
        if ( !empty($success_url)) {
            $s_url = $success_url;
        } else {
            $s_url = get_permalink( $d_stripe_general['direct_stripe_success_page'] );
        }
        $success_query	= isset($params['success_query']) ? $params['success_query'] : '';
        if ( !empty($success_query)) {
            $pres_query = $success_query;
            preg_match_all("/([^,= ]+):([^,= ]+)/", $pres_query, $r);
            $s_query = array_combine($r[1], $r[2]);
        }
        //Add query arguments for redirection
        if( !empty($s_query) ) {
            $s_url = add_query_arg( $s_query , $s_url);
        }
        //Redirection after success
        $return = array(
            'id'	=> '2',
            'url'	=> $s_url
        );

    } else {

        $return = array(
            'id'	=> '1',
            'message' => $d_stripe_general['direct_stripe_success_message']
        );

    }

    wp_send_json($return);

}
catch(Exception $e)
{
//Email client
  if( isset($d_stripe_emails['direct_stripe_user_error_emails_checkbox'])  && $d_stripe_emails['direct_stripe_user_error_emails_checkbox'] === '1' ) {

	  $email_subject = apply_filters( 'direct_stripe_error_user_email_subject', $d_stripe_emails['direct_stripe_user_error_email_subject'], $token, $amount, $currency, $email_address, $description, $user_id, $button_id );
      $email_content = apply_filters( 'direct_stripe_error_user_email_content', $d_stripe_emails['direct_stripe_user_error_email_content'], $token, $amount, $currency, $email_address, $description, $user_id, $button_id );

	  wp_mail( $email_address, $email_subject , $email_content, $headers );
  }
//Email admin
  if( isset($d_stripe_emails['direct_stripe_admin_error_emails_checkbox'])  && $d_stripe_emails['direct_stripe_admin_error_emails_checkbox'] === '1' ) {

  	  $email_subject = apply_filters( 'direct_stripe_error_admin_email_subject', $d_stripe_emails['direct_stripe_admin_error_email_subject'], $token, $amount, $currency, $email_address, $description, $user_id, $button_id );
      $email_content = apply_filters( 'direct_stripe_error_admin_email_content', $d_stripe_emails['direct_stripe_admin_error_email_content'], $token, $amount, $currency, $email_address, $description, $user_id, $button_id );

      wp_mail( $admin_email, $email_subject , $email_content, $headers );
  }
	
// Add custom action before redirection
	do_action( 'direct_stripe_before_error_redirection',  $chargeID, $post_id, $button_id, $user_id );

    //Answer for ajax
    if( isset($d_stripe_general['direct_stripe_use_redirections'])  && $d_stripe_general['direct_stripe_use_redirections'] === '1' ) {

        $error_url	= isset($params['error_url']) ? $params['error_url'] : '';
        if ( !empty($error_url)) {
            $e_url = $error_url;
        } else {
            $e_url = get_permalink( $d_stripe_general['direct_stripe_error_page'] );
        }
        $error_query	= isset($params['error_query']) ? $params['error_query'] : '';
        if ( !empty($error_query)) {
            $pres_query = $error_query;
            preg_match_all("/([^,= ]+):([^,= ]+)/", $pres_query, $e);
            $e_query = array_combine($e[1], $e[2]);
        }
        //Add query arguments for redirection
        if( !empty($e_query) ) {
            $e_url = add_query_arg( $e_query , $e_url);
        }
        //Redirection after success
        $return = array(
            'id'	=> '2',
            'url'	=> $e_url
        );

    } else {

        $return = array(
            'id'	=> '1',
            'message' => $d_stripe_general['direct_stripe_error_message']
        );

    }

    wp_send_json($return);

	  error_log("unable to proceed with:" . $_POST['stripeEmail'].
		", error:" . $e->getMessage());
}
?>