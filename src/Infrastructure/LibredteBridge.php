<?php
namespace Sii\BoletaDte\Infrastructure;

use libredte\lib\Core\Application;

/**
 * Centralized access to LibreDTE application using libredte_lib() when available.
 */
class LibredteBridge {
    /** Map plugin env to libredte env string. */
    private static function mapEnv(string $env): string {
        $env = Settings::normalize_environment($env);
        return match ($env) {
            '1' => 'prod',
            '2' => 'dev',
            default => 'cert',
        };
    }

    /** Returns the LibreDTE Application instance, configured for current environment if possible. */
    public static function getApp(Settings $settings): mixed {
        $env = self::mapEnv($settings->get_environment());
        $debug = defined('WP_DEBUG') ? (bool) constant('WP_DEBUG') : false;
        try {
            // Prefer global helper if present
            if (function_exists('libredte_lib')) {
                /** @var callable $fn */
                $fn = 'libredte_lib';
                return $fn($env, $debug);
            }
        } catch (\Throwable $_) {
            // fall through to Application singleton
        }
        // Fallback
        return Application::getInstance();
    }

    /** Returns the Billing package or null if not available. */
    public static function getBillingPackage(Settings $settings): mixed {
        try {
            $app = self::getApp($settings);
            if (!is_object($app) || !method_exists($app, 'getPackageRegistry')) { return null; }
            $registry = $app->getPackageRegistry();
            if (!is_object($registry) || !method_exists($registry, 'getBillingPackage')) { return null; }
            return $registry->getBillingPackage();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Returns the Billing Document component or null if not available. */
    public static function getBillingDocumentComponent(Settings $settings): mixed {
        try {
            $billing = self::getBillingPackage($settings);
            if (!is_object($billing) || !method_exists($billing, 'getDocumentComponent')) { return null; }
            return $billing->getDocumentComponent();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Returns the TradingParties component (if available) or null. */
    public static function getTradingPartiesComponent(Settings $settings): mixed {
        try {
            $billing = self::getBillingPackage($settings);
            if (!is_object($billing) || !method_exists($billing, 'getTradingPartiesComponent')) { return null; }
            return $billing->getTradingPartiesComponent();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Returns EmisorFactory if available. */
    public static function getEmisorFactory(Settings $settings): mixed {
        try {
            $tp = self::getTradingPartiesComponent($settings);
            if (!is_object($tp) || !method_exists($tp, 'getEmisorFactory')) { return null; }
            return $tp->getEmisorFactory();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Returns ReceptorFactory if available. */
    public static function getReceptorFactory(Settings $settings): mixed {
        try {
            $tp = self::getTradingPartiesComponent($settings);
            if (!is_object($tp) || !method_exists($tp, 'getReceptorFactory')) { return null; }
            return $tp->getReceptorFactory();
        } catch (\Throwable $e) {
            return null;
        }
    }
}

class_alias(LibredteBridge::class, 'SII_Boleta_LibredteBridge');
