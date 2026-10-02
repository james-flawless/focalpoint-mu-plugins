<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Plugin
{
    private static ?self $instance = null;

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    private FocalPoint_EasyCoach_LTI_REST_Controller $rest_controller;

    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->configuration  = new FocalPoint_EasyCoach_LTI_Configuration();
        $this->rest_controller = new FocalPoint_EasyCoach_LTI_REST_Controller(
            $this->configuration
        );

        add_action('rest_api_init', array($this->rest_controller, 'register_routes'));
    }
}

