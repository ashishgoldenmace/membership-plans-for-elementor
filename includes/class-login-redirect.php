<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LoginRedirect {
    
    public function __construct() {
        add_filter( 'kivicare_login_redirect', [ $this, 'kivicare_login_redirect' ], 10, 3 );  
    }

    private function has_active_membership( $user_id ) {
        if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) return false;
        return pmpro_hasMembershipLevel( null, $user_id );
    }
    
    private function get_membership_page_url() {
        return home_url( '/membresia-cedes/' );
    }

    public function kivicare_login_redirect( $redirect_to, $user ) {
        if ( $this->has_active_membership( $user->ID ) ) {
            return $redirect_to;
        } else {
            return $this->get_membership_page_url();
        }
    }
}
