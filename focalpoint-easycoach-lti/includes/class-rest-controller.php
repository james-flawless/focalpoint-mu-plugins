<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_REST_Controller
{
    private const NAMESPACE = 'focalpoint-lti/v1';

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    public function __construct(FocalPoint_EasyCoach_LTI_Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function register_routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/jwks',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'unavailable'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/authorize',
            array(
                'methods'             => array('GET', 'POST'),
                'callback'            => array($this, 'unavailable'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/token',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'unavailable'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/lineitems/(?P<lineitem_id>[A-Za-z0-9._~-]+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'unavailable'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/lineitems/(?P<lineitem_id>[A-Za-z0-9._~-]+)/scores',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'unavailable'),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Fail closed until the complete endpoint implementation is available.
     *
     * @return WP_Error
     */
    public function unavailable()
    {
        $code = $this->configuration->is_ready()
            ? 'fp_easycoach_lti_not_implemented'
            : 'fp_easycoach_lti_not_configured';

        return new WP_Error(
            $code,
            'The Focal Point EasyCoach LTI service is not available.',
            array('status' => 503)
        );
    }
}

