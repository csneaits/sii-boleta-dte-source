<?php
namespace Sii\BoletaDte\Presentation\Admin;

/**
 * Shared admin UI styles to provide the azure background treatment across pages.
 */
class AdminStyles {
    private static bool $printed = false;

    /**
     * Opens a styled container with the shared azure background.
     */
    public static function open_container( string $extra_classes = '' ): void {
        $classes = trim( 'wrap sii-admin-surface ' . $extra_classes );
        $escaped = self::escape_attr( $classes );
        echo '<div class="' . $escaped . '">';
        self::print_styles();
    }

    /**
     * Closes the styled container.
     */
    public static function close_container(): void {
        echo '</div>';
    }

    private static function print_styles(): void {
        if ( self::$printed ) {
            return;
        }
        self::$printed = true;
        echo '<style>' . self::styles() . '</style>';
    }

    private static function styles(): string {
        return <<<'CSS'
.sii-admin-surface {
    position: relative;
    padding: 2.5rem 2.4rem 3rem;
    border-radius: 22px;
    background: linear-gradient(135deg, #f5f9ff 0%, #eef2ff 100%);
    box-shadow: 0 28px 55px rgba(15, 23, 42, 0.12);
    overflow: hidden;
}
.sii-admin-surface::before,
.sii-admin-surface::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}
.sii-admin-surface::before {
    inset: -140px auto auto -160px;
    width: 360px;
    height: 360px;
    background: radial-gradient(circle at center, rgba(58, 123, 213, 0.35), transparent 70%);
}
.sii-admin-surface::after {
    inset: auto -150px -180px auto;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle at center, rgba(0, 210, 255, 0.22), transparent 75%);
}
.sii-admin-surface > * {
    position: relative;
    z-index: 1;
}
.sii-admin-surface h1 {
    margin-top: 0;
    margin-bottom: 1.1rem;
    font-size: 2.1rem;
    color: #0f172a;
}
.sii-admin-subtitle,
.sii-admin-surface h1 + p {
    color: #334155;
    font-size: 1.05rem;
    margin: -0.2rem 0 1.6rem;
    max-width: 820px;
}
.sii-admin-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    padding: 1.9rem 2.1rem;
    margin-top: 1.6rem;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.12);
    border: 1px solid rgba(226, 232, 240, 0.7);
    backdrop-filter: blur(6px);
}
.sii-admin-card--compact {
    padding: 1.5rem 1.75rem;
}
.sii-admin-card--form form {
    margin: 0;
}
.sii-admin-card--form .submit {
    margin-top: 1.4rem;
}
.sii-admin-card h2 {
    margin-top: 0;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    color: #1e3a8a;
}
.sii-admin-card h2::after {
    content: '';
    flex: 1;
    height: 2px;
    border-radius: 999px;
    background: linear-gradient(90deg, rgba(58, 123, 213, 0.28), transparent);
}
.sii-admin-card p.description,
.sii-admin-card .description {
    color: #475569;
}
.sii-admin-card table.form-table {
    width: 100%;
    border-spacing: 0;
}
.sii-admin-card table.form-table th {
    padding: 0 1rem 1.1rem 0;
    text-align: left;
    color: #1e293b;
    width: 28%;
}
.sii-admin-card table.form-table td {
    padding: 0 0 1.1rem;
}
.sii-admin-card table.form-table td .description {
    margin-top: 0.35rem;
}
.sii-admin-card table.widefat,
.sii-admin-card table.wp-list-table,
.sii-admin-surface table.widefat,
.sii-admin-surface table.wp-list-table {
    border-radius: 16px;
    overflow: hidden;
    border: none;
    box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12);
}
.sii-admin-card table.widefat thead th,
.sii-admin-card table.wp-list-table thead th,
.sii-admin-surface table.widefat thead th,
.sii-admin-surface table.wp-list-table thead th {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(14, 165, 233, 0.12));
    color: #0f172a;
    font-weight: 600;
    padding: 0.9rem 1.1rem;
    border-bottom: 1px solid rgba(148, 163, 184, 0.35);
}
.sii-admin-card table.widefat tbody td,
.sii-admin-card table.wp-list-table tbody td,
.sii-admin-surface table.widefat tbody td,
.sii-admin-surface table.wp-list-table tbody td {
    padding: 0.85rem 1.1rem;
    background: rgba(255, 255, 255, 0.94);
    border-bottom: 1px solid rgba(226, 232, 240, 0.72);
}
.sii-admin-card table.widefat tbody tr:nth-child(even) td,
.sii-admin-card table.wp-list-table tbody tr:nth-child(even) td,
.sii-admin-surface table.widefat tbody tr:nth-child(even) td,
.sii-admin-surface table.wp-list-table tbody tr:nth-child(even) td {
    background: rgba(241, 245, 249, 0.9);
}
.sii-admin-card table.widefat tbody tr:last-child td,
.sii-admin-card table.wp-list-table tbody tr:last-child td,
.sii-admin-surface table.widefat tbody tr:last-child td,
.sii-admin-surface table.wp-list-table tbody tr:last-child td {
    border-bottom: none;
}
.sii-admin-surface .button,
.sii-admin-surface .button-primary {
    border-radius: 999px;
    padding: 0.5rem 1.35rem;
    font-weight: 600;
    border: none;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
    box-shadow: 0 12px 24px rgba(37, 99, 235, 0.16);
}
.sii-admin-surface .button.button-primary,
.sii-admin-surface .button-primary {
    background: linear-gradient(135deg, #2563eb 0%, #0ea5e9 100%);
    color: #fff;
}
.sii-admin-surface .button:hover,
.sii-admin-surface .button-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 18px 30px rgba(37, 99, 235, 0.24);
}
.sii-admin-actions {
    margin: 1.4rem 0 0;
}
.sii-admin-surface input[type="text"],
.sii-admin-surface input[type="number"],
.sii-admin-surface input[type="date"],
.sii-admin-surface input[type="email"],
.sii-admin-surface input[type="password"],
.sii-admin-surface select,
.sii-admin-surface textarea {
    border-radius: 12px;
    border: 1px solid rgba(148, 163, 184, 0.5);
    padding: 0.55rem 0.75rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
    background: rgba(255, 255, 255, 0.95);
    width: 100%;
}
.sii-admin-surface input[type="text"].regular-text,
.sii-admin-surface input[type="number"].regular-text,
.sii-admin-surface input[type="password"].regular-text,
.sii-admin-surface select.regular-text {
    width: 25rem;
    max-width: 100%;
}
.sii-admin-surface textarea:focus,
.sii-admin-surface select:focus,
.sii-admin-surface input:focus {
    outline: none;
    border-color: rgba(37, 99, 235, 0.75);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
}
.sii-admin-surface .notice {
    border-radius: 14px;
    padding: 0.95rem 1.1rem;
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.1);
    background: rgba(255, 255, 255, 0.96);
}
.sii-admin-checklist {
    list-style: none;
    padding: 0;
    margin: 1rem 0 0;
    color: #1f2937;
}
.sii-admin-checklist li {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    margin-bottom: 0.6rem;
    padding: 0.55rem 0.85rem;
    border-radius: 12px;
    background: rgba(148, 163, 184, 0.12);
}
.sii-admin-status-icon {
    width: 28px;
    height: 28px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    background: rgba(59, 130, 246, 0.25);
    color: #1e3a8a;
}
.sii-admin-status-icon.is-bad {
    background: rgba(220, 38, 38, 0.2);
    color: #b91c1c;
}
.sii-admin-status-icon.is-info {
    background: rgba(148, 163, 184, 0.25);
    color: #1f2937;
}
.sii-admin-checklist li strong {
    color: inherit;
}
.sii-admin-surface .sii-boleta-diag-status {
    list-style: none;
    padding: 0;
    margin: 1rem 0 1.2rem;
}
.sii-admin-surface .sii-boleta-diag-status li {
    background: rgba(148, 163, 184, 0.12);
    border-radius: 12px;
    padding: 0.55rem 0.8rem;
    margin-bottom: 0.55rem;
}
.sii-admin-surface .sii-boleta-diag-action {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 1.1rem 1.4rem;
    border-radius: 16px;
    background: rgba(59, 130, 246, 0.08);
    margin: 0.9rem 0;
}
.sii-admin-surface .sii-boleta-diag-dump {
    border-radius: 12px !important;
    background: rgba(15, 23, 42, 0.08) !important;
    border: 1px solid rgba(148, 163, 184, 0.35) !important;
}
.sii-admin-surface .sii-dte-cert-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.8rem;
    align-items: center;
}
.sii-admin-surface .sii-dte-logo-field {
    display: grid;
    grid-template-columns: 80px 1fr;
    gap: 1rem;
    align-items: center;
}
.sii-admin-surface .sii-dte-logo-field img {
    width: 80px;
    height: 80px;
    object-fit: contain;
    border-radius: 12px;
    background: rgba(15, 23, 42, 0.08);
}
.sii-admin-surface .sii-boleta-modal {
    background: #fff;
    border-radius: 18px;
    padding: 1.5rem;
    width: min(92vw, 520px);
    box-shadow: 0 28px 60px rgba(15, 23, 42, 0.18);
}
.sii-admin-surface .sii-boleta-modal-backdrop {
    background: rgba(15, 23, 42, 0.6);
}
.sii-admin-surface .sii-admin-card--table {
    padding: 1.4rem 1.6rem;
}
.sii-admin-surface .sii-admin-card--table table {
    margin: 0;
}
.sii-admin-surface .sii-admin-card--table .button-link {
    color: #2563eb;
    font-weight: 600;
}
.sii-admin-surface .sii-admin-card--table .button-link:hover {
    color: #1d4ed8;
}
@media (max-width: 900px) {
    .sii-admin-surface {
        padding: 2.3rem 1.6rem 2.5rem;
    }
    .sii-admin-card {
        padding: 1.6rem 1.5rem;
    }
}
@media (max-width: 782px) {
    .sii-admin-surface {
        padding: 2rem 1.2rem 2.3rem;
    }
    .sii-admin-card table.form-table th,
    .sii-admin-card table.form-table td {
        width: 100%;
        display: block;
    }
    .sii-admin-card table.form-table th {
        padding-bottom: 0.45rem;
    }
    .sii-admin-card table.form-table td {
        padding-bottom: 1.2rem;
    }
    .sii-admin-surface .sii-dte-logo-field {
        grid-template-columns: 1fr;
    }
}
@media (prefers-color-scheme: dark) {
    .sii-admin-surface {
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.88) 0%, rgba(37, 99, 235, 0.22) 100%);
        box-shadow: 0 30px 55px rgba(2, 6, 23, 0.7);
    }
    .sii-admin-surface::before {
        background: radial-gradient(circle at center, rgba(59, 130, 246, 0.42), transparent 75%);
    }
    .sii-admin-surface::after {
        background: radial-gradient(circle at center, rgba(14, 165, 233, 0.32), transparent 75%);
    }
    .sii-admin-surface h1,
    .sii-admin-subtitle,
    .sii-admin-card h2,
    .sii-admin-card,
    .sii-admin-card p,
    .sii-admin-card li,
    .sii-admin-surface label {
        color: #e2e8f0;
    }
    .sii-admin-card {
        background: rgba(15, 23, 42, 0.85);
        border-color: rgba(51, 65, 85, 0.75);
        box-shadow: 0 32px 65px rgba(2, 6, 23, 0.75);
    }
    .sii-admin-card h2::after {
        background: linear-gradient(90deg, rgba(96, 165, 250, 0.5), transparent);
    }
    .sii-admin-surface input[type="text"],
    .sii-admin-surface input[type="number"],
    .sii-admin-surface input[type="date"],
    .sii-admin-surface input[type="email"],
    .sii-admin-surface input[type="password"],
    .sii-admin-surface select,
    .sii-admin-surface textarea {
        background: rgba(15, 23, 42, 0.78);
        color: #f8fafc;
        border-color: rgba(148, 163, 184, 0.55);
    }
    .sii-admin-card table.widefat thead th,
    .sii-admin-card table.wp-list-table thead th,
    .sii-admin-surface table.widefat thead th,
    .sii-admin-surface table.wp-list-table thead th {
        color: #f8fafc;
        border-bottom-color: rgba(148, 163, 184, 0.45);
    }
    .sii-admin-card table.widefat tbody td,
    .sii-admin-card table.wp-list-table tbody td,
    .sii-admin-surface table.widefat tbody td,
    .sii-admin-surface table.wp-list-table tbody td {
        background: rgba(15, 23, 42, 0.82);
        color: #e2e8f0;
        border-bottom-color: rgba(51, 65, 85, 0.8);
    }
    .sii-admin-card table.widefat tbody tr:nth-child(even) td,
    .sii-admin-card table.wp-list-table tbody tr:nth-child(even) td,
    .sii-admin-surface table.widefat tbody tr:nth-child(even) td,
    .sii-admin-surface table.wp-list-table tbody tr:nth-child(even) td {
        background: rgba(30, 41, 59, 0.78);
    }
    .sii-admin-surface .notice {
        background: rgba(15, 23, 42, 0.82);
        color: #e2e8f0;
    }
    .sii-admin-surface .sii-boleta-diag-action {
        background: rgba(37, 99, 235, 0.22);
    }
    .sii-admin-checklist li,
    .sii-admin-surface .sii-boleta-diag-status li {
        background: rgba(59, 130, 246, 0.25);
    }
    .sii-admin-status-icon {
        background: rgba(59, 130, 246, 0.45);
        color: #bfdbfe;
    }
    .sii-admin-status-icon.is-bad {
        background: rgba(248, 113, 113, 0.35);
        color: #fecaca;
    }
    .sii-admin-status-icon.is-info {
        background: rgba(148, 163, 184, 0.35);
        color: #e2e8f0;
    }
}
CSS;
    }

    private static function escape_attr( string $value ): string {
        if ( function_exists( 'esc_attr' ) ) {
            return esc_attr( $value );
        }
        return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
    }
}
