<?php
/**
 * Plugin Name: UP Frontend Editor
 * Description: Permet d'éditer le contenu texte directement en front-end avec contenteditable
 * Version: 1.0.0
 * Author: UP
 * Text Domain: up-frontend-editor
 */

if (!defined('ABSPATH')) {
    exit;
}

class UP_Frontend_Editor {
    
    private static $instance = null;
    const OPTION_DEFAULT_ENABLED = 'up_fe_default_enabled';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hooks
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_footer', [$this, 'render_save_button']);
        add_filter('the_content', [$this, 'make_content_editable'], 999);
        
        // AJAX pour sauvegarder
        add_action('wp_ajax_up_save_frontend_content', [$this, 'ajax_save_content']);

        // Réglages admin
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    /**
     * Vérifie si l'utilisateur peut éditer
     */
    private function can_edit() {
        return is_user_logged_in() && current_user_can('edit_posts');
    }
    
    /**
     * Vérifie si le mode édition est activé (cookie utilisateur ou réglage par défaut)
     */
    private function is_edit_mode_enabled() {
        if (!$this->can_edit() || is_admin() || !is_singular()) {
            return false;
        }
        // Cookie prioritaire
        if (isset($_COOKIE['up_fe_enabled'])) {
            return $_COOKIE['up_fe_enabled'] === '1';
        }
        // Option par défaut (activée/désactivée globalement)
        $default = get_option(self::OPTION_DEFAULT_ENABLED, '0');
        return $default === '1';
    }
    
    /**
     * Charge les scripts et styles
     */
    public function enqueue_scripts() {
        if (!$this->can_edit() || is_admin()) {
            return;
        }
        
        // Styles (inclure dashicons pour les icônes)
        wp_enqueue_style('dashicons');
        // Media frame pour choisir des images
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }
        wp_enqueue_style(
            'up-frontend-editor',
            plugin_dir_url(__FILE__) . 'assets/css/frontend-editor.css',
            [],
            '1.0.0'
        );
        
        // Script principal
        wp_enqueue_script(
            'up-frontend-editor',
            plugin_dir_url(__FILE__) . 'assets/js/frontend-editor.js',
            ['jquery'],
            '1.1.0',
            true
        );
        
        // Passer les données au script
        wp_localize_script('up-frontend-editor', 'upFrontendEditor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('up_frontend_editor'),
            'postId' => get_the_ID(),
            'canEdit' => $this->can_edit(),
            'enabled' => $this->is_edit_mode_enabled()
        ]);
    }
    
    /**
     * Rend le contenu éditable
     */
    public function make_content_editable($content) {
        if (!$this->can_edit() || is_admin() || !is_singular()) {
            return $content;
        }
        // Appliquer uniquement si le mode est activé
        if (!$this->is_edit_mode_enabled()) {
            return $content;
        }
        
        // Wrapper le contenu avec un attribut data pour l'identifier
        $post_id = get_the_ID();
        $wrapped_content = '<div class="up-editable-content" data-post-id="' . esc_attr($post_id) . '">';
        $wrapped_content .= $this->add_contenteditable($content);
        $wrapped_content .= '</div>';
        
        return $wrapped_content;
    }
    
    /**
     * Ajoute contenteditable aux éléments de texte
     */
    private function add_contenteditable($content) {
        // Parser le HTML
        $dom = new DOMDocument('1.0', 'UTF-8');
        
        // Supprimer les warnings pour le HTML mal formé
        libxml_use_internal_errors(true);
        
        // Charger le contenu avec encodage UTF-8
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        libxml_clear_errors();
        
        // Éléments de texte à rendre éditables
        $text_elements = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'td', 'th', 'figcaption', 'span', 'a', 'strong', 'em', 'div'];
        
        foreach ($text_elements as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            
            foreach ($elements as $element) {
                // Ne pas rendre éditable si c'est déjà un conteneur avec d'autres éléments complexes
                if ($this->has_only_text_content($element)) {
                    $element->setAttribute('contenteditable', 'true');
                    $element->setAttribute('class', trim($element->getAttribute('class') . ' up-editable'));
                }
            }
        }
        
        // Rendre les images remplaçables (click pour ouvrir la médiathèque)
        $images = $dom->getElementsByTagName('img');
        foreach ($images as $img) {
            // Ajouter une classe et un data attribut pour l'éditeur
            $img->setAttribute('class', trim($img->getAttribute('class') . ' up-editable-image'));
            // Si une ID WP est déjà présente (par ex. data-id), la préserver, sinon laisser vide
            if (!$img->hasAttribute('data-attachment-id')) {
                $img->setAttribute('data-attachment-id', '');
            }
        }

        // Retourner le HTML modifié
        $html = $dom->saveHTML();
        
        // Nettoyer les balises ajoutées par DOMDocument
        $html = str_replace('<?xml encoding="UTF-8">', '', $html);
        
        return $html;
    }
    
    /**
     * Vérifie si un élément contient principalement du texte
     */
    private function has_only_text_content($element) {
        // Si l'élément a des enfants, vérifier qu'ils sont simples
        if ($element->hasChildNodes()) {
            foreach ($element->childNodes as $child) {
                // Autoriser les nœuds texte et quelques balises inline simples
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $allowed_inline = ['strong', 'em', 'b', 'i', 'span', 'a', 'br'];
                    if (!in_array($child->nodeName, $allowed_inline)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }
    
    /**
     * Rend le bouton de sauvegarde
     */
    public function render_save_button() {
        if (!$this->can_edit() || is_admin() || !is_singular()) {
            return;
        }
        $enabled = $this->is_edit_mode_enabled();
        ?>
        <div id="up-frontend-editor-toolbar" class="up-frontend-editor-toolbar <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>">
            <div class="up-toolbar-content">
                <span class="up-toolbar-title">Édition front</span>
                <label class="up-toggle">
                    <input type="checkbox" id="up-fe-toggle" <?php checked($enabled); ?> />
                    <span class="up-toggle-label">Activer l'édition</span>
                </label>
                <button id="up-save-content" class="up-save-button" <?php disabled(!$enabled); ?> >
                    <span class="dashicons dashicons-yes"></span>
                    Enregistrer
                </button>
                <button id="up-cancel-edit" class="up-cancel-button" <?php disabled(!$enabled); ?> >
                    <span class="dashicons dashicons-no"></span>
                    Annuler
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Sauvegarde le contenu
     */
    public function ajax_save_content() {
        check_ajax_referer('up_frontend_editor', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $content = isset($_POST['content']) ? wp_kses_post($_POST['content']) : '';
        
        if (!$post_id) {
            wp_send_json_error(['message' => 'ID de post invalide']);
        }
        
        // Nettoyer le contenu des attributs contenteditable
        $content = $this->clean_content($content);
        
        // Mettre à jour le post
        $result = wp_update_post([
            'ID' => $post_id,
            'post_content' => $content
        ]);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => 'Contenu sauvegardé avec succès',
            'post_id' => $post_id
        ]);
    }
    
    /**
     * Nettoie le contenu des attributs d'édition
     */
    private function clean_content($content) {
        // Retirer les attributs contenteditable et classes up-editable
        $content = preg_replace('/\s*contenteditable="true"\s*/', ' ', $content);
        $content = preg_replace('/\s*class="([^"]*)\s*up-editable\s*([^"]*)"\s*/', ' class="$1$2" ', $content);
        $content = preg_replace('/\s*class="([^"]*)\s*up-editable-image\s*([^"]*)"\s*/', ' class="$1$2" ', $content);
        $content = preg_replace('/\s*data-attachment-id="[^"]*"\s*/', ' ', $content);
        $content = preg_replace('/\s*class=""\s*/', '', $content);
        
        // Retirer le wrapper
        $content = preg_replace('/<div class="up-editable-content"[^>]*>/', '', $content);
        $content = preg_replace('/<\/div>\s*$/', '', $content);
        
        return trim($content);
    }

    /**
     * Page de réglages
     */
    public function add_settings_page() {
        add_options_page(
            'UP Frontend Editor',
            'UP Frontend Editor',
            'manage_options',
            'up-frontend-editor',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        register_setting('up_fe_settings_group', self::OPTION_DEFAULT_ENABLED);
        add_settings_section('up_fe_main', 'Paramètres généraux', '__return_false', 'up-frontend-editor');
        add_settings_field(
            'up_fe_default_enabled_field',
            'Activer l\'édition par défaut',
            [$this, 'render_default_enabled_field'],
            'up-frontend-editor',
            'up_fe_main'
        );
    }
    
    public function render_default_enabled_field() {
        $value = get_option(self::OPTION_DEFAULT_ENABLED, '0');
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_DEFAULT_ENABLED) . '" value="1" ' . checked('1', $value, false) . ' /> Activer l\'édition front par défaut pour les éditeurs</label>';
    }
    
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>UP Frontend Editor</h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields('up_fe_settings_group');
                    do_settings_sections('up-frontend-editor');
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

// Initialiser le plugin
UP_Frontend_Editor::get_instance();
