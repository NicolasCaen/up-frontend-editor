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
        add_filter('the_title', [$this, 'make_title_editable'], 999, 2);
        
        // AJAX pour sauvegarder
        add_action('wp_ajax_up_save_frontend_content', [$this, 'ajax_save_content']);
        add_action('wp_ajax_up_save_page_title', [$this, 'ajax_save_page_title']);
        add_action('wp_ajax_up_create_new_post', [$this, 'ajax_create_new_post']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

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
            null
        );
        
        // Script principal (dépend de jQuery)
        wp_enqueue_script(
            'up-frontend-editor',
            plugin_dir_url(__FILE__) . 'assets/js/frontend-editor.js',
            ['jquery'],
            null,
            true
        );
        
        // Passer les données au script
        wp_localize_script('up-frontend-editor', 'upFrontendEditor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('up_frontend_editor'),
            'postId' => get_the_ID(),
            'postType' => get_post_type(),
            'canEdit' => $this->can_edit(),
            'enabled' => $this->is_edit_mode_enabled(),
            'restUrl' => esc_url_raw(rest_url('up-fe/v1/')),
            'restNonce' => wp_create_nonce('wp_rest')
        ]);
    }

    /**
     * Enregistre les routes REST
     */
    public function register_rest_routes() {
        register_rest_route('up-fe/v1', '/blocks/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => function($request) {
                $post_id = (int)$request['id'];
                $this->dbg('rest:get_blocks:start', ['post_id' => $post_id]);
                $post = get_post($post_id);
                if (!$post) {
                    $this->dbg('rest:get_blocks:error', ['post_id' => $post_id, 'reason' => 'not_found']);
                    return new WP_Error('not_found', 'Post introuvable', ['status' => 404]);
                }
                $raw = $post->post_content;
                $has = has_blocks($raw);
                $blocks = $has ? parse_blocks($raw) : [];
                $path = [];
                $with_ids = $has ? $this->inject_ids_into_blocks_recursive($blocks, $path) : [];
                $serialized = $has ? serialize_blocks($with_ids) : $raw;
                if ($has) {
                    // Injecter les data-upfe-* au moment du rendu des blocs
                    add_filter('render_block', [$this, 'render_block_with_uids'], 10, 2);
                    $rendered = do_blocks($serialized);
                    remove_filter('render_block', [$this, 'render_block_with_uids'], 10);
                } else {
                    $rendered = apply_filters('the_content', $raw);
                }
                // Ajouter contenteditable pour debug visuel
                if ($has) { $rendered = $this->add_contenteditable($rendered); }
                $this->dbg('rest:get_blocks:success', ['post_id' => $post_id, 'blocks' => $has ? count($blocks) : 0]);
                return rest_ensure_response([
                    'has_blocks' => $has,
                    'blocks_count' => $has ? count($blocks) : 0,
                    'rendered_html' => $rendered,
                    'serialized' => $serialized,
                ]);
            },
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('up-fe/v1', '/save/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => function($request) {
                $post_id = (int)$request['id'];
                // Lire le payload JSON
$body = json_decode($request->get_body(), true) ?: [];
$base_serialized = isset($body['base_serialized']) ? (string)$body['base_serialized'] : '';
$content_html = isset($body['content_html']) ? (string)$body['content_html'] : '';
$updates = isset($body['updates']) && is_array($body['updates']) ? $body['updates'] : [];

$this->dbg('rest:save:payload', [
    'updates_present' => !empty($updates),
    'content_html_len' => strlen($content_html),
    'base_serialized_len' => strlen($base_serialized),
]);
                $this->dbg('rest:save:start', ['post_id' => $post_id]);
                $post = get_post($post_id);
                if (!$post) {
                    $this->dbg('rest:save:error', ['post_id' => $post_id, 'reason' => 'not_found']);
                    return new WP_Error('not_found', 'Post introuvable', ['status' => 404]);
                }
                $original = $post->post_content;
                $updates = $request->get_param('updates');
                $content_html = $request->get_param('content_html');
                if (is_string($content_html) && $content_html !== '') {
                    // Nettoyer toute trace d'édition/boutons avant traitement
                    $content_html = $this->clean_content($content_html);
                }
                $this->dbg('rest:save:payload', [
                    'updates_present' => !empty($updates),
                    'content_html_len' => is_string($content_html) ? strlen($content_html) : 0
                ]);
                if (empty($updates) && !empty($content_html)) {
                    // Construire updates_map depuis le HTML fourni
                    $updates = $this->build_updates_map($content_html);
                    $keys_count = 0; foreach ($updates as $k => $arr) { $keys_count += is_array($arr) ? count($arr) : 0; }
                    $this->dbg('rest:save:updates_map', ['blocks' => count($updates), 'keys' => $keys_count]);
                }
                $original = $post->post_content;

                // Choisir la base des blocs pour l’update
                $blocks_for_update = [];
                $has_base = false;
                if (!empty($base_serialized) && has_blocks($base_serialized)) {
                    $blocks_for_update = parse_blocks($base_serialized);
                    $has_base = true;
                } elseif (has_blocks($original)) {
                    $blocks_for_update = parse_blocks($original);
                    $has_base = true;
                }
                
                // Si une updates_map ID-based est fournie, on la privilégie
                if (!empty($updates) && $has_base) {
                    $path = [];
                    $updated_blocks = $this->update_blocks_with_ids($blocks_for_update, $updates, $path);
                    $serialized = serialize_blocks($updated_blocks);
                    $this->dbg('rest:save:path', ['mode' => 'ids']);
                } else {
                    // Fallback séquentiel
                    if (!empty($content_html) && !$has_base) {
                        // Pas de blocs: sauvegarde du HTML nettoyé tel quel
                        $this->dbg('rest:save:path', ['mode' => 'sequential']);
                        $serialized = $this->clean_content($content_html);
                    } elseif (!empty($content_html) && $has_base) {
                        // On a des blocs mais pas d'updates_map: appliquer le HTML sur l’arbre
                        $this->dbg('rest:save:path', ['mode' => 'sequential_on_blocks']);
                        $updated_blocks = $this->update_blocks_content($blocks_for_update, $content_html);
                        $serialized = serialize_blocks($updated_blocks);
                    } else {
                        $this->dbg('rest:save:error', ['post_id' => $post_id, 'reason' => 'no_updates']);
                        return new WP_Error('no_updates', 'Aucune mise à jour fournie', ['status' => 400]);
                    }
                }
                // Écriture des fichiers de debug pour comparaison
                // 1) Base utilisée pour le parsing/updates (si disponible)
                $base_used_serialized = '';
                if ($has_base && !empty($blocks_for_update)) {
                    $base_used_serialized = serialize_blocks($blocks_for_update);
                } else {
                    $base_used_serialized = (string)$base_serialized;
                }
                $this->write_debug_json(
                    $post_id,
                    'parse-serialized-' . $post_id . '.json',
                    [
                        'post_id' => $post_id,
                        'length' => strlen($base_used_serialized),
                        'serialized' => $base_used_serialized,
                    ]
                );

                // 2) Contenu final sérialisé qui va être sauvegardé
                $this->write_debug_json(
                    $post_id,
                    'parse-modified-' . $post_id . '.json',
                    [
                        'post_id' => $post_id,
                        'length' => strlen($serialized),
                        'serialized' => $serialized,
                    ]
                );

                $this->dbg('rest:save:serialized', ['len' => strlen($serialized)]);
                
                // Vérifier les permissions avant la mise à jour
                if (!current_user_can('edit_post', $post_id)) {
                    $this->dbg('rest:save:error', ['post_id' => $post_id, 'reason' => 'permission_denied']);
                    return new WP_Error('permission_denied', 'Vous n\'avez pas la permission de modifier ce contenu', ['status' => 403]);
                }
                
                $ok = wp_update_post(['ID' => $post_id, 'post_content' => $serialized], true);
                if (is_wp_error($ok)) {
                    $this->dbg('rest:save:error', ['post_id' => $post_id, 'wp_error' => $ok->get_error_message()]);
                    return new WP_Error('update_failed', $ok->get_error_message(), ['status' => 500]);
                }
                
                // Vérifier que la mise à jour a bien été effectuée
                clean_post_cache($post_id); // Forcer l'invalidation du cache
                $updated_post = get_post($post_id);
                $saved_content = $updated_post ? $updated_post->post_content : '';
                $this->dbg('rest:save:success', [
                    'post_id' => $post_id,
                    'wp_update_result' => $ok,
                    'saved_length' => strlen($saved_content),
                    'expected_length' => strlen($serialized),
                    'match' => $saved_content === $serialized
                ]);
                
                return rest_ensure_response(['success' => true, 'post_id' => $post_id]);
            },
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * Rend le titre éditable
     */
    public function make_title_editable($title, $id = null) {
        // Appliquer uniquement sur le titre principal de la page
        if (!$this->can_edit() || is_admin() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $title;
        }
        
        if (!$this->is_edit_mode_enabled()) {
            return $title;
        }
        
        $post_id = get_the_ID();
        if ($id && $id !== $post_id) {
            return $title;
        }
        
        return '<span class="up-editable-title" contenteditable="true" data-post-id="' . esc_attr($post_id) . '">' . $title . '</span>';
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
        
        // IMPORTANT: récupérer le contenu RAW depuis le post (avant do_blocks)
        // car the_content reçoit le HTML déjà rendu
        $post_id = get_the_ID();
        clean_post_cache($post_id); // Forcer la lecture depuis la DB
        $post = get_post($post_id);
        $raw_content = $post ? $post->post_content : $content;
        
        $has_gb = has_blocks($raw_content);
        $this->dbg('make_content_editable:check', [
            'has_blocks' => $has_gb,
            'content_start' => substr($raw_content, 0, 200)
        ]);
        
        // Générer le HTML éditable avec des IDs stables par bloc si Gutenberg est présent
        if ($has_gb) {
            $blocks = parse_blocks($raw_content);
            $path = [];
            $blocks_with_ids = $this->inject_ids_into_blocks_recursive($blocks, $path);
            // Sérialiser puis rendre via do_blocks pour obtenir le HTML final
            $serialized = serialize_blocks($blocks_with_ids);
            $editable_html = do_blocks($serialized);
            // Rendre toutes les zones textuelles éditables sur le HTML final
            $editable_html = $this->add_contenteditable($editable_html);
            $this->dbg('render_with_ids', [
                'blocks' => count($blocks),
                'html_sample' => substr($editable_html, 0, 300)
            ]);
        } else {
            // Fallback: ancienne méthode sur HTML rendu
            $editable_html = $this->add_contenteditable($content);
            $this->dbg('render_plain_html');
        }

        // Wrapper le contenu avec un attribut data pour l'identifier
        $post_id = get_the_ID();
        $wrapped_content = '<div class="up-editable-content" data-post-id="' . esc_attr($post_id) . '">';
        $wrapped_content .= $editable_html;
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
            
            // Récupérer l'ID d'attachement depuis différentes sources possibles
            $attachment_id = '';
            if ($img->hasAttribute('data-id')) {
                $attachment_id = $img->getAttribute('data-id');
            } elseif (preg_match('/wp-image-(\d+)/', $img->getAttribute('class'), $matches)) {
                $attachment_id = $matches[1];
            }
            
            // Stocker l'ID dans data-attachment-id pour l'éditeur
            if (!$img->hasAttribute('data-attachment-id')) {
                $img->setAttribute('data-attachment-id', $attachment_id);
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
                <button id="up-create-post" class="up-create-button" <?php disabled(!$enabled); ?> >
                    <span class="dashicons dashicons-plus"></span>
                    Créer un nouveau post
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
     * AJAX: Crée un nouveau post
     */
    public function ajax_create_new_post() {
        check_ajax_referer('up_frontend_editor', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        
        $post_type = isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : 'post';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $content_html = isset($_POST['content_html']) ? wp_unslash($_POST['content_html']) : '';
        $updates_json = isset($_POST['updates']) ? wp_unslash($_POST['updates']) : '';
        $base_serialized = isset($_POST['base_serialized']) ? wp_unslash($_POST['base_serialized']) : '';
        
        // Vérifier que l'utilisateur peut créer ce type de post
        $post_type_obj = get_post_type_object($post_type);
        if (!$post_type_obj || !current_user_can($post_type_obj->cap->create_posts)) {
            wp_send_json_error(['message' => 'Vous ne pouvez pas créer ce type de contenu']);
        }
        
        $this->dbg('create_post:start', [
            'post_type' => $post_type,
            'has_content' => !empty($content_html),
            'has_base' => !empty($base_serialized),
            'updates_json_len' => strlen($updates_json)
        ]);
        
        // Traiter le contenu si fourni
        $post_content = '<!-- wp:paragraph --><p>Votre contenu ici...</p><!-- /wp:paragraph -->';
        
        // Nettoyer le HTML
        if (!empty($content_html)) {
            $content_html = $this->clean_content($content_html);
        }
        
        // Décoder les updates
        $updates = !empty($updates_json) ? json_decode($updates_json, true) : [];
        
        // Logger le contenu des updates pour debug
        $updates_preview = [];
        foreach ($updates as $uid => $fields) {
            foreach ($fields as $key => $data) {
                $preview = is_array($data) && isset($data['content']) 
                    ? substr(strip_tags($data['content']), 0, 50) 
                    : (is_string($data) ? substr($data, 0, 50) : 'N/A');
                $updates_preview[$uid . '.' . $key] = $preview;
            }
        }
        $this->dbg('create_post:updates_decoded', [
            'updates_count' => count($updates), 
            'is_array' => is_array($updates),
            'preview' => $updates_preview
        ]);
        
        // Priorité 1: Si on a des updates ET une base, appliquer les updates
        if (!empty($updates) && !empty($base_serialized) && has_blocks($base_serialized)) {
            $blocks = parse_blocks($base_serialized);
            $path = [];
            $updated_blocks = $this->update_blocks_with_ids($blocks, $updates, $path);
            $post_content = serialize_blocks($updated_blocks);
            $this->dbg('create_post:content_from_updates', ['blocks' => count($blocks), 'updates' => count($updates)]);
        }
        // Priorité 2: Si on a du HTML avec data-upfe-*, construire l'updates_map et appliquer
        elseif (!empty($content_html) && !empty($base_serialized) && has_blocks($base_serialized)) {
            // Construire updates_map depuis le HTML si pas déjà fourni
            if (empty($updates)) {
                $updates = $this->build_updates_map($content_html);
                $this->dbg('create_post:updates_from_html', ['updates_count' => count($updates)]);
            }
            if (!empty($updates)) {
                $blocks = parse_blocks($base_serialized);
                $path = [];
                $updated_blocks = $this->update_blocks_with_ids($blocks, $updates, $path);
                $post_content = serialize_blocks($updated_blocks);
                $this->dbg('create_post:content_processed', ['blocks' => count($blocks), 'updates' => count($updates)]);
            } else {
                // Pas d'updates: utiliser la base telle quelle
                $post_content = $base_serialized;
                $this->dbg('create_post:using_base_as_is');
            }
        }
        // Priorité 3: Utiliser la base telle quelle
        elseif (!empty($base_serialized) && has_blocks($base_serialized)) {
            $post_content = $base_serialized;
            $this->dbg('create_post:using_base_as_is');
        }
        else {
            $this->dbg('create_post:no_content_to_process', [
                'has_html' => !empty($content_html),
                'has_base' => !empty($base_serialized)
            ]);
        }
        
        // Définir le titre
        if (empty($title)) {
            $title = 'Nouveau ' . $post_type_obj->labels->singular_name;
        }
        
        // Debug: écrire le contenu qui va être inséré pour inspection
        $upload_dir = wp_upload_dir();
        $debug_dir = trailingslashit($upload_dir['basedir']) . 'up-fe-debug/';
        if (!file_exists($debug_dir)) {
            wp_mkdir_p($debug_dir);
        }
        $stamp = date('Ymd-His');
        $uniq = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true);
        $debug_base = trailingslashit($debug_dir) . 'create-' . $stamp . '-' . $uniq;
        
        // Fichiers de debug: contenu sérialisé, rendu HTML, et métadonnées
        @file_put_contents($debug_base . '.gb.txt', $post_content);
        $render_preview = has_blocks($post_content) ? do_blocks($post_content) : $post_content;
        @file_put_contents($debug_base . '.html', $render_preview);
        $meta = [
            'post_type' => $post_type,
            'title' => $title,
            'has_blocks' => has_blocks($post_content),
            'updates_count' => is_array($updates) ? count($updates) : 0,
            'updates_preview' => isset($updates_preview) ? $updates_preview : [],
            'base_serialized_len' => strlen($base_serialized),
            'result_serialized_len' => strlen($post_content)
        ];
        @file_put_contents($debug_base . '.json', wp_json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->dbg('create_post:debug_written', ['base' => basename($debug_base)]);
        
        // Créer le post
        $new_post_id = wp_insert_post([
            'post_type' => $post_type,
            'post_title' => $title,
            'post_content' => $post_content,
            'post_status' => 'draft',
            'post_author' => get_current_user_id()
        ], true);
        
        if (is_wp_error($new_post_id)) {
            $this->dbg('create_post:error', ['wp_error' => $new_post_id->get_error_message()]);
            wp_send_json_error(['message' => $new_post_id->get_error_message()]);
        }
        
        $permalink = get_permalink($new_post_id);
        $this->dbg('create_post:success', ['post_id' => $new_post_id, 'permalink' => $permalink]);
        
        wp_send_json_success([
            'message' => 'Post créé avec succès',
            'post_id' => $new_post_id,
            'permalink' => $permalink
        ]);
    }
    
    /**
     * AJAX: Sauvegarde le titre de la page
     */
    public function ajax_save_page_title() {
        check_ajax_referer('up_frontend_editor', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }
        
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $new_title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $this->dbg('save_title:start', ['post_id' => $post_id, 'title_len' => strlen($new_title)]);
        
        if (!$post_id) {
            $this->dbg('save_title:error', ['reason' => 'invalid_post_id']);
            wp_send_json_error(['message' => 'ID de post invalide']);
        }
        
        if (empty($new_title)) {
            $this->dbg('save_title:error', ['reason' => 'empty_title']);
            wp_send_json_error(['message' => 'Le titre ne peut pas être vide']);
        }
        
        // Mettre à jour le titre
        $result = wp_update_post([
            'ID' => $post_id,
            'post_title' => $new_title
        ]);
        
        if (is_wp_error($result)) {
            $this->dbg('save_title:error', ['wp_error' => $result->get_error_message()]);
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        $this->dbg('save_title:success', ['post_id' => $post_id]);
        wp_send_json_success([
            'message' => 'Titre sauvegardé avec succès',
            'post_id' => $post_id
        ]);
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
        // Sanitize en autorisant 'style' et certains data-* nécessaires à l'analyse
        $raw_content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        $allowed = wp_kses_allowed_html('post');
        $tags_with_style = ['div','span','p','h1','h2','h3','h4','h5','h6','li','blockquote','figcaption','a','img','figure'];
        foreach ($tags_with_style as $t) {
            if (!isset($allowed[$t])) { $allowed[$t] = []; }
            $allowed[$t]['style'] = true; // ex: background-image sur cover
            $allowed[$t]['class'] = true; // conserver classes wp-image-XX
            $allowed[$t]['data-attachment-id'] = true; // utilisé temporairement
            // IDs front pour mapping côté serveur
            $allowed[$t]['data-upfe-block'] = true;
            $allowed[$t]['data-upfe-field'] = true;
            $allowed[$t]['data-upfe-key'] = true;
        }
        // Autoriser aussi srcset/sizes sur img
        if (!isset($allowed['img'])) { $allowed['img'] = []; }
        $allowed['img']['srcset'] = true;
        $allowed['img']['sizes'] = true;
        $new_content = wp_kses($raw_content, $allowed);
        
        if (!$post_id) {
            wp_send_json_error(['message' => 'ID de post invalide']);
        }
        
        // Construire la map d'updates à partir des IDs dans le HTML posté
        $updates_map = $this->build_updates_map($new_content);

        // Nettoyer le contenu des attributs d'édition (utile pour le fallback séquentiel)
        $new_content = $this->clean_content($new_content);
        
        // Récupérer le contenu original du post
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post introuvable']);
        }
        
        $original_content = $post->post_content;
        $this->dbg('save_content:original', [
            'has_blocks' => has_blocks($original_content),
            'content_start' => substr($original_content, 0, 200)
        ]);
        
        // Vérifier si le contenu contient des blocs Gutenberg
        if (has_blocks($original_content)) {
            // Parser les blocs existants
            $blocks = parse_blocks($original_content);

            if (!empty($updates_map)) {
                // Chemin de mise à jour basé sur IDs stables
                $path = [];
                $updated_blocks = $this->update_blocks_with_ids($blocks, $updates_map, $path);
            } else {
                // Fallback: mapping séquentiel
                $updated_blocks = $this->update_blocks_content($blocks, $new_content);
            }

            // Sérialiser les blocs mis à jour
            $content = serialize_blocks($updated_blocks);
        } else {
            // Si pas de blocs Gutenberg, utiliser le contenu directement
            $content = $new_content;
        }
        
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
        // Retirer les boutons d'édition d'images
        $content = preg_replace('/<button[^>]*class="[^"]*up-image-edit-btn[^"]*"[^>]*>.*?<\/button>/s', '', $content);
        $content = preg_replace('/<button[^>]*class="[^"]*up-cover-edit-btn[^"]*"[^>]*>.*?<\/button>/s', '', $content);
        
        // Retirer les wrappers d'images
        $content = preg_replace('/<span class="up-image-wrapper">/', '', $content);
        $content = preg_replace('/<\/span>(?=\s*(?:<\/figure>|<\/div>|<\/p>|$))/', '', $content);
        
        // Retirer les classes ajoutées
        $content = preg_replace('/\s*class="([^"]*)\s*up-has-editable-image\s*([^"]*)"\s*/', ' class="$1$2" ', $content);
        
        // Retirer les attributs contenteditable et classes up-editable
        $content = preg_replace('/\s*contenteditable="true"\s*/', ' ', $content);
        $content = preg_replace('/\s*class="([^"]*)\s*up-editable\s*([^"]*)"\s*/', ' class="$1$2" ', $content);
        $content = preg_replace('/\s*class="([^"]*)\s*up-editable-image\s*([^"]*)"\s*/', ' class="$1$2" ', $content);
        $content = preg_replace('/\s*data-attachment-id="[^"]*"\s*/', ' ', $content);
        $content = preg_replace('/\s*class=""\s*/', '', $content);
        
        // NOTE: $content provient de innerHTML de .up-editable-content côté client,
        // il ne contient donc PAS le wrapper lui-même. Ne pas tenter de retirer
        // un éventuel </div> final, cela pourrait supprimer un div légitime et
        // casser le HTML soumis.
        // Si un jour on envoie le wrapper complet, on pourra réactiver une logique
        // conditionnelle stricte ici (en vérifiant que le contenu commence bien
        // par <div class="up-editable-content" ...> avant de le retirer).
        
        return trim($content);
    }
    
    /**
     * Met à jour le contenu des blocs Gutenberg avec le nouveau HTML
     */
    private function update_blocks_content($blocks, $new_html) {
        // Parser le nouveau HTML pour extraire le contenu
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $new_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        // Extraire tous les éléments de contenu dans l'ordre
        $new_elements = [];
        $this->extract_content_elements($dom->documentElement, $new_elements);
        
        // Index pour parcourir les nouveaux éléments
        $element_index = 0;
        
        // Mettre à jour récursivement les blocs
        return $this->update_blocks_recursive($blocks, $new_elements, $element_index);
    }
    
    /**
     * Extrait les éléments de contenu du DOM dans l'ordre
     */
    private function extract_content_elements($node, &$elements) {
        if (!$node) return;
        
        if ($node->nodeType === XML_ELEMENT_NODE) {
            // Éléments de contenu à extraire
            $content_tags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'figcaption', 'a', 'img'];
            
            if (in_array(strtolower($node->nodeName), $content_tags)) {
                $elements[] = [
                    'tag' => strtolower($node->nodeName),
                    'content' => $this->get_inner_html($node)
                ];
            }
            
            // Parcourir les enfants
            if ($node->hasChildNodes()) {
                foreach ($node->childNodes as $child) {
                    $this->extract_content_elements($child, $elements);
                }
            }
        }
    }
    
    /**
     * Obtient le HTML interne d'un nœud
     */
    private function get_inner_html($node) {
        $innerHTML = '';
        $children = $node->childNodes;
        
        foreach ($children as $child) {
            $innerHTML .= $node->ownerDocument->saveHTML($child);
        }
        
        return $innerHTML;
    }
    
    /**
     * Logger simplifié vers error_log
     */
    private function dbg($tag, $data = []) {
        if (!is_array($data)) { $data = ['msg' => (string)$data]; }
        // Clip gros champs
        foreach ($data as $k => $v) {
            if (is_string($v) && strlen($v) > 500) {
                $data[$k] = substr($v, 0, 500) . '...(' . strlen($v) . ' bytes)';
            }
        }
        error_log('[UP_FE] ' . $tag . ' ' . wp_json_encode($data));
    }

    /**
     * Retourne le chemin du dossier de debug (dans uploads/up-fe-debug), le crée si nécessaire
     */
    private function get_debug_dir() {
        $upload = wp_upload_dir();
        $base = isset($upload['basedir']) ? $upload['basedir'] : WP_CONTENT_DIR . '/uploads';
        $dir = trailingslashit($base) . 'up-fe-debug';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }

    /**
     * Écrit un fichier JSON de debug de manière sécurisée
     */
    private function write_debug_json($post_id, $filename, $payload) {
        try {
            $dir = $this->get_debug_dir();
            // Assurer un nom fichier simple
            $file = trailingslashit($dir) . $filename;
            // Encoder proprement en JSON lisible
            $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $json = '{}';
            }
            // Écrire atomiquement si possible
            @file_put_contents($file, $json);
        } catch (\Throwable $e) {
            $this->dbg('debug_file:error', ['post_id' => $post_id, 'file' => $filename, 'err' => $e->getMessage()]);
        }
    }
    
    /**
     * Injecte des IDs stables (data-upfe-*) dans chaque bloc récursivement
     */
   
        private function inject_ids_into_blocks_recursive($blocks, &$path) {
            $updated = [];
            foreach ($blocks as $i => $block) {
                $path[] = $i; // descendre
                $uid = implode('.', $path);
        
                $updated_block = $block;
        
                // Propager l'UID dans les attrs pour pouvoir ré-injecter les marqueurs au rendu
                if (!isset($updated_block['attrs']) || !is_array($updated_block['attrs'])) {
                    $updated_block['attrs'] = [];
                }
                $updated_block['attrs']['_upfeUid'] = $uid;
        
                if (!empty($block['innerHTML'])) {
                    $updated_block['innerHTML'] = $this->inject_field_ids_into_block_html($block['innerHTML'], $uid);
                }
                if (!empty($block['innerBlocks'])) {
                    $updated_block['innerBlocks'] = $this->inject_ids_into_blocks_recursive($block['innerBlocks'], $path);
                }
                $updated[] = $updated_block;
                array_pop($path); // remonter
            }
            return $updated;
        }

        /**
 * Filtre render_block: injecte data-upfe-* dans le HTML final pour un bloc disposant d'un _upfeUid
 */
public function render_block_with_uids($block_content, $block) {
    if (!is_array($block)) {
        return $block_content;
    }
    $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
    if (empty($attrs['_upfeUid'])) {
        return $block_content;
    }
    $uid = $attrs['_upfeUid'];
    return $this->inject_field_ids_into_block_html($block_content, $uid);
}
    /**
     * Ajoute data-upfe-block, data-upfe-field, data-upfe-key dans le HTML d'un bloc
     * et rend les éléments de texte éditables (contenteditable)
     */
    private function inject_field_ids_into_block_html($html, $block_uid) {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Compteurs par type
        $counters = [
            'p' => 0, 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0,
            'li' => 0, 'blockquote' => 0, 'figcaption' => 0, 'a' => 0, 'img' => 0, 'cover-bg' => 0
        ];

        // 1) Cover background
        $xpath = new DOMXPath($dom);
        $bgDivs = $xpath->query("//*[contains(@class, 'wp-block-cover__image-background')]");
        foreach ($bgDivs as $bg) {
            /** @var DOMElement $bg */
            $key = 'cover-bg-' . $counters['cover-bg']++;
            $bg->setAttribute('data-upfe-block', $block_uid);
            $bg->setAttribute('data-upfe-field', 'cover');
            $bg->setAttribute('data-upfe-key', $key);
        }

        // 2) Éléments textuels
        $text_tags = ['p','h1','h2','h3','h4','h5','h6','li','blockquote','figcaption','a'];
        foreach ($text_tags as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            foreach ($nodes as $node) {
                if ($this->has_only_text_content($node)) {
                    $node->setAttribute('contenteditable', 'true');
                    $node->setAttribute('class', trim($node->getAttribute('class') . ' up-editable'));
                }
                $key = $tag . '-' . $counters[$tag]++;
                $node->setAttribute('data-upfe-block', $block_uid);
                $node->setAttribute('data-upfe-field', $tag === 'a' ? 'link' : 'text');
                $node->setAttribute('data-upfe-key', $key);
            }
        }

        // 3) Images
        $imgs = $dom->getElementsByTagName('img');
        foreach ($imgs as $img) {
            $img->setAttribute('class', trim($img->getAttribute('class') . ' up-editable-image'));
            $img->setAttribute('data-upfe-block', $block_uid);
            $img->setAttribute('data-upfe-field', 'image');
            $img->setAttribute('data-upfe-key', 'img-' . $counters['img']++);
        }

        $out = $dom->saveHTML();
        return str_replace('<?xml encoding="UTF-8">', '', $out);
    }

    /**
     * Construit une map d'updates à partir du HTML posté contenant data-upfe-*
     * Structure: [ blockUid => [ key => [ 'type' => 'text|link|image|cover', ... ] ] ]
     */
    private function build_updates_map($html) {
        $updates = [];
        if (empty($html)) return $updates;

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[@data-upfe-block and @data-upfe-field and @data-upfe-key]');
        $this->dbg('build_updates_map:nodes', ['count' => $nodes ? $nodes->length : 0]);
        foreach ($nodes as $node) {
            /** @var DOMElement $node */
            $uid = (string)$node->getAttribute('data-upfe-block');
            $field = (string)$node->getAttribute('data-upfe-field');
            $key = (string)$node->getAttribute('data-upfe-key');
            if ($uid === '' || $key === '') continue;

            if (!isset($updates[$uid])) $updates[$uid] = [];

            if ($field === 'text' || $field === 'link') {
                $entry = [
                    'type' => $field,
                    'content' => $this->get_inner_html($node),
                ];
                if ($field === 'link' && $node->hasAttribute('href')) {
                    $entry['href'] = $node->getAttribute('href');
                }
                $updates[$uid][$key] = $entry;
            } elseif ($field === 'image') {
                // Chercher l'img porteur
                $img = $node->nodeName === 'img' ? $node : null;
                if (!$img) continue;
                $id = null;
                if ($img->hasAttribute('data-attachment-id')) {
                    $id = intval($img->getAttribute('data-attachment-id'));
                } elseif (preg_match('/wp-image-(\d+)/', $img->getAttribute('class'), $m)) {
                    $id = intval($m[1]);
                }
                $updates[$uid][$key] = [
                    'type' => 'image',
                    'url' => $img->getAttribute('src'),
                    'srcset' => $img->getAttribute('srcset'),
                    'sizes' => $img->getAttribute('sizes'),
                    'width' => $img->getAttribute('width'),
                    'height' => $img->getAttribute('height'),
                    'alt' => $img->getAttribute('alt'),
                    'id' => $id,
                ];
            } elseif ($field === 'cover') {
                $style = $node->getAttribute('style');
                $url = '';
                if (preg_match('/background-image:\s*url\(["\']?([^"\']+)["\']?\)/', $style, $m)) {
                    $url = $m[1];
                }
                $id = null;
                if ($node->hasAttribute('data-attachment-id')) {
                    $id = intval($node->getAttribute('data-attachment-id'));
                } elseif (preg_match('/wp-image-(\d+)/', $node->getAttribute('class'), $m)) {
                    $id = intval($m[1]);
                }
                $updates[$uid][$key] = [
                    'type' => 'cover',
                    'url' => $url,
                    'id' => $id,
                ];
            }
        }

        $this->dbg('build_updates_map:done', ['blocks' => count($updates)]);
        return $updates;
    }

    /**
     * Met à jour l'arbre de blocs Gutenberg avec la map d'updates (ID-based)
     */
    private function update_blocks_with_ids($blocks, $updates_map, &$path) {
        $updated = [];
        foreach ($blocks as $i => $block) {
            $path[] = $i;
            $uid = implode('.', $path);
            $updated_block = $block;

            $updates_for_block = isset($updates_map[$uid]) ? $updates_map[$uid] : [];
            $this->dbg('update_block:enter', ['uid' => $uid, 'updates' => is_array($updates_for_block) ? count($updates_for_block) : 0, 'blockName' => $block['blockName'] ?? '']);
            $updated_inner_html = '';
            if (!empty($updates_for_block) && !empty($block['innerHTML'])) {
                // IMPORTANT: Injecter les marqueurs data-upfe-* dans le innerHTML AVANT d'appliquer les updates
                // car le innerHTML brut de la DB n'a pas ces marqueurs
                $html_with_markers = $this->inject_field_ids_into_block_html($block['innerHTML'], $uid);
                
                $updated_inner_html = $this->apply_updates_to_block_html($html_with_markers, $uid, $updates_for_block);
                if ($updated_inner_html !== '') {
                    // Logger le HTML mis à jour
                    $this->dbg('update_block:after_apply', ['uid' => $uid, 'html_sample' => substr(strip_tags($updated_inner_html), 0, 100)]);
                    
                    // Mettre à jour les attrs en se basant sur le HTML mis à jour
                    $updated_block['attrs'] = $this->update_block_attributes_from_html($updated_block, $updated_inner_html);
                    
                    // Logger les attrs mis à jour
                    if (!empty($updated_block['attrs']['content'])) {
                        $this->dbg('update_block:attrs_updated', ['uid' => $uid, 'content' => substr($updated_block['attrs']['content'], 0, 100)]);
                    }
                    
                    // Retirer les marqueurs d'édition
                    $cleaned = $this->strip_editing_markers_from_html($updated_inner_html);
                    $updated_block['innerHTML'] = $cleaned;
                    // Synchroniser innerContent pour les blocs feuilles (sans innerBlocks)
                    if (array_key_exists('innerContent', $updated_block) && is_array($updated_block['innerContent']) && empty($updated_block['innerBlocks'])) {
                        $updated_block['innerContent'] = [$cleaned];
                        $this->dbg('update_block:inner_content_synced', ['uid' => $uid]);
                    }
                    
                    // Logger le HTML final nettoyé
                    $this->dbg('update_block:final_html', ['uid' => $uid, 'html_sample' => substr(strip_tags($cleaned), 0, 100)]);
                    $this->dbg('update_block:html_updated', ['uid' => $uid]);
                }
            }

            // Parcourir les innerBlocks
            if (!empty($block['innerBlocks'])) {
                $updated_block['innerBlocks'] = $this->update_blocks_with_ids($block['innerBlocks'], $updates_map, $path);
            }

            $updated[] = $updated_block;
            array_pop($path);
        }
        return $updated;
    }

    /**
     * Applique les updates d'un bloc dans son innerHTML
     */
    private function apply_updates_to_block_html($html, $uid, $updates_for_block) {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        foreach ($updates_for_block as $key => $entry) {
            $nodes = $xpath->query('//*[@data-upfe-block="' . htmlspecialchars($uid) . '"][@data-upfe-key="' . htmlspecialchars($key) . '"]');
            if (!$nodes || $nodes->length === 0) {
                $this->dbg('apply_update:not_found', ['uid' => $uid, 'key' => $key, 'query' => '//*[@data-upfe-block="' . $uid . '"][@data-upfe-key="' . $key . '"]']);
                continue;
            }
            /** @var DOMElement $node */
            $node = $nodes->item(0);
            $type = $entry['type'];
            $this->dbg('apply_update:found', ['uid' => $uid, 'key' => $key, 'type' => $type]);

            if ($type === 'text' || $type === 'link') {
                // Remplacer le contenu interne
                while ($node->firstChild) { $node->removeChild($node->firstChild); }
                $content = isset($entry['content']) ? (string)$entry['content'] : '';
                if ($content !== '') {
                    $frag = $dom->createDocumentFragment();
                    // Si appendXML échoue (contenu non XML), fallback en text node
                    if (@$frag->appendXML($content)) {
                        $node->appendChild($frag);
                    } else {
                        $node->appendChild($dom->createTextNode(wp_strip_all_tags($content)));
                    }
                }
                if ($type === 'link' && isset($entry['href'])) {
                    $node->setAttribute('href', $entry['href']);
                }
            } elseif ($type === 'image') {
                if (strtolower($node->nodeName) !== 'img') continue;
                if (!empty($entry['url'])) { $node->setAttribute('src', $entry['url']); }
                if (!empty($entry['srcset'])) { $node->setAttribute('srcset', $entry['srcset']); } else { $node->removeAttribute('srcset'); }
                if (!empty($entry['sizes'])) { $node->setAttribute('sizes', $entry['sizes']); } else { $node->removeAttribute('sizes'); }
                if (!empty($entry['width'])) { $node->setAttribute('width', $entry['width']); }
                if (!empty($entry['height'])) { $node->setAttribute('height', $entry['height']); }
                if (isset($entry['alt'])) { $node->setAttribute('alt', $entry['alt']); }
                if (!empty($entry['id'])) {
                    $node->setAttribute('data-attachment-id', (string)intval($entry['id']));
                    // Mettre à jour la classe wp-image-XXX
                    $classes = preg_split('/\s+/', (string)$node->getAttribute('class')); $classes = $classes ?: [];
                    $classes = array_values(array_filter($classes, function($c){ return strpos($c, 'wp-image-') !== 0 ? true : false; }));
                    $classes[] = 'wp-image-' . intval($entry['id']);
                    $node->setAttribute('class', trim(implode(' ', $classes)));
                }
            } elseif ($type === 'cover') {
                // Mettre background-image sur style
                $style = (string)$node->getAttribute('style');
                if (!empty($entry['url'])) {
                    if (preg_match('/background-image:\s*url\([^)]*\)/', $style)) {
                        $style = preg_replace('/background-image:\s*url\([^)]*\)/', 'background-image: url(' . $entry['url'] . ')', $style);
                    } else {
                        $style = rtrim($style, '; ') . '; background-image: url(' . $entry['url'] . ');';
                    }
                    $node->setAttribute('style', $style);
                }
                if (!empty($entry['id'])) {
                    $node->setAttribute('data-attachment-id', (string)intval($entry['id']));
                    // Optionnel: classe wp-image-XXX
                    $classes = preg_split('/\s+/', (string)$node->getAttribute('class')); $classes = $classes ?: [];
                    $classes = array_values(array_filter($classes, function($c){ return strpos($c, 'wp-image-') !== 0 ? true : false; }));
                    $classes[] = 'wp-image-' . intval($entry['id']);
                    $node->setAttribute('class', trim(implode(' ', $classes)));
                }
            }
        }

        $out = $dom->saveHTML();
        return str_replace('<?xml encoding="UTF-8">', '', $out);
    }

    /**
     * Retire les marqueurs d'édition (data-upfe-*, contenteditable, classes up-*)
     */
    private function strip_editing_markers_from_html($html) {
        // Retirer data-upfe-*
        $html = preg_replace('/\sdata-upfe-(block|field|key)="[^"]*"/i', '', $html);
        // Retirer contenteditable
        $html = preg_replace('/\scontenteditable="true"/i', '', $html);
        // Retirer classes up-editable et up-editable-image
        $html = preg_replace('/\sclass="([^"]*)\s*up-editable\s*([^"]*)"/i', ' class="$1$2"', $html);
        $html = preg_replace('/\sclass="([^"]*)\s*up-editable-image\s*([^"]*)"/i', ' class="$1$2"', $html);
        // Nettoyer class="" vides
        $html = preg_replace('/\sclass=""/i', '', $html);
        // Retirer data-attachment-id (les IDs sont maintenant dans attrs)
        $html = preg_replace('/\sdata-attachment-id="[^"]*"/i', '', $html);
        return $html;
    }
    
    /**
     * Met à jour récursivement les blocs avec le nouveau contenu
     */
    private function update_blocks_recursive($blocks, $new_elements, &$element_index) {
        $updated_blocks = [];
        
        foreach ($blocks as $block) {
            // Copier le bloc
            $updated_block = $block;
            
            // Mettre à jour d'abord le HTML du bloc
            $updated_inner_html = '';
            if (!empty($block['innerHTML'])) {
                $updated_inner_html = $this->update_block_html($block['innerHTML'], $new_elements, $element_index);
                if ($updated_inner_html !== '') {
                    $updated_block['innerHTML'] = $updated_inner_html;
                }
            }

            // Puis mettre à jour les attributs du bloc à partir du HTML mis à jour
            if (!empty($block['attrs'])) {
                $source_html_for_attrs = $updated_inner_html !== '' ? $updated_inner_html : (!empty($block['innerHTML']) ? $block['innerHTML'] : '');
                $updated_block['attrs'] = $this->update_block_attributes_from_html($updated_block, $source_html_for_attrs);
            }
            
            // Si le bloc a des innerBlocks, les mettre à jour récursivement
            if (!empty($block['innerBlocks'])) {
                $updated_block['innerBlocks'] = $this->update_blocks_recursive($block['innerBlocks'], $new_elements, $element_index);
            }
            
            $updated_blocks[] = $updated_block;
        }
        
        return $updated_blocks;
    }
    
    /**
     * Met à jour les attributs d'un bloc (titre, images de bannière, etc.)
     */
    private function update_block_attributes_from_html($block, $updated_inner_html) {
        $attrs = $block['attrs'] ?? [];
        $block_name = $block['blockName'] ?? '';
        if (empty($attrs)) {
            return $attrs;
        }

        // Utiliser le HTML mis à jour si fourni, sinon le HTML original
        $source_html = $updated_inner_html ?: ($block['innerHTML'] ?? '');
        if ($source_html === '') {
            return $attrs;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $source_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Bloc de titre (core/heading)
        if ($block_name === 'core/heading') {
            // Récupérer le premier h1-h6 et son texte
            for ($lvl = 1; $lvl <= 6; $lvl++) {
                $nodes = $dom->getElementsByTagName('h' . $lvl);
                if ($nodes->length > 0) {
                    $heading = $nodes->item(0);
                    $attrs['content'] = trim($heading->textContent);
                    $this->dbg('update_attrs:heading', ['level' => $lvl, 'content' => substr($attrs['content'], 0, 100)]);
                    break;
                }
            }
        }

        // Bloc de couverture/bannière (core/cover)
        if ($block_name === 'core/cover') {
            $xpath = new DOMXPath($dom);
            $bgDivs = $xpath->query("//*[contains(@class, 'wp-block-cover__image-background')]");
            if ($bgDivs->length > 0) {
                /** @var DOMElement $bgDiv */
                $bgDiv = $bgDivs->item(0);
                $style = $bgDiv->getAttribute('style');
                if (preg_match('/background-image:\s*url\(["\']?([^"\']+)["\']?\)/', $style, $m)) {
                    $attrs['url'] = $m[1];
                }
                if ($bgDiv->hasAttribute('data-attachment-id')) {
                    $attrs['id'] = intval($bgDiv->getAttribute('data-attachment-id'));
                } elseif (preg_match('/wp-image-(\d+)/', $bgDiv->getAttribute('class'), $m)) {
                    $attrs['id'] = intval($m[1]);
                }
            } else {
                $images = $dom->getElementsByTagName('img');
                if ($images->length > 0) {
                    $img = $images->item(0);
                    if ($img->hasAttribute('src')) {
                        $attrs['url'] = $img->getAttribute('src');
                    }
                    if ($img->hasAttribute('data-attachment-id')) {
                        $attrs['id'] = intval($img->getAttribute('data-attachment-id'));
                    } elseif (preg_match('/wp-image-(\d+)/', $img->getAttribute('class'), $m)) {
                        $attrs['id'] = intval($m[1]);
                    }
                }
            }
        }

        // Bloc Media & Text (core/media-text)
        if ($block_name === 'core/media-text' && isset($attrs['mediaUrl'])) {
            $images = $dom->getElementsByTagName('img');
            if ($images->length > 0) {
                $img = $images->item(0);
                if ($img->hasAttribute('src')) {
                    $attrs['mediaUrl'] = $img->getAttribute('src');
                }
                if ($img->hasAttribute('data-attachment-id')) {
                    $attrs['mediaId'] = intval($img->getAttribute('data-attachment-id'));
                } elseif (preg_match('/wp-image-(\d+)/', $img->getAttribute('class'), $m)) {
                    $attrs['mediaId'] = intval($m[1]);
                }
            }
        }

        // Bloc Image (core/image)
        if ($block_name === 'core/image') {
            $images = $dom->getElementsByTagName('img');
            if ($images->length > 0) {
                $img = $images->item(0);
                if ($img->hasAttribute('src')) {
                    $attrs['url'] = $img->getAttribute('src');
                }
                if ($img->hasAttribute('data-attachment-id')) {
                    $attrs['id'] = intval($img->getAttribute('data-attachment-id'));
                } elseif (preg_match('/wp-image-(\d+)/', $img->getAttribute('class'), $m)) {
                    $attrs['id'] = intval($m[1]);
                }
                if ($img->hasAttribute('alt')) {
                    $attrs['alt'] = $img->getAttribute('alt');
                }
                if ($img->hasAttribute('width')) {
                    $attrs['width'] = intval($img->getAttribute('width'));
                }
                if ($img->hasAttribute('height')) {
                    $attrs['height'] = intval($img->getAttribute('height'));
                }
            }
        }

        return $attrs;
    }
    
    /**
     * Met à jour le HTML d'un bloc avec les nouveaux éléments
     */
    private function update_block_html($html, $new_elements, &$element_index) {
        if (empty($html) || $element_index >= count($new_elements)) {
            return $html;
        }
        
        // Parser le HTML du bloc
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        // Mettre à jour les éléments de contenu
        $this->update_dom_elements($dom->documentElement, $new_elements, $element_index);
        
        // Retourner le HTML mis à jour
        $updated_html = $dom->saveHTML();
        $updated_html = str_replace('<?xml encoding="UTF-8">', '', $updated_html);
        
        return $updated_html;
    }
    
    /**
     * Met à jour les éléments du DOM avec le nouveau contenu
     */
    private function update_dom_elements($node, $new_elements, &$element_index) {
        if (!$node || $element_index >= count($new_elements)) return;
        
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);
            $content_tags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'figcaption', 'a'];
            
            // Si c'est un élément de contenu et qu'il correspond au tag attendu
            if (in_array($tag, $content_tags) && 
                isset($new_elements[$element_index]) && 
                $new_elements[$element_index]['tag'] === $tag) {
                
                // Remplacer le contenu interne
                while ($node->firstChild) {
                    $node->removeChild($node->firstChild);
                }
                
                // Ajouter le nouveau contenu
                $fragment = $node->ownerDocument->createDocumentFragment();
                $fragment->appendXML($new_elements[$element_index]['content']);
                $node->appendChild($fragment);
                
                $element_index++;
                return; // Ne pas parcourir les enfants car on vient de les remplacer
            }
            
            // Gérer les images
            if ($tag === 'img' && isset($new_elements[$element_index]) && $new_elements[$element_index]['tag'] === 'img') {
                // Parser le nouveau HTML de l'image
                $img_dom = new DOMDocument('1.0', 'UTF-8');
                libxml_use_internal_errors(true);
                $img_dom->loadHTML('<?xml encoding="UTF-8">' . $new_elements[$element_index]['content'], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();
                
                $new_img = $img_dom->getElementsByTagName('img')->item(0);
                if ($new_img) {
                    // Copier les attributs src, srcset, etc.
                    if ($new_img->hasAttribute('src')) {
                        $node->setAttribute('src', $new_img->getAttribute('src'));
                    }
                    if ($new_img->hasAttribute('srcset')) {
                        $node->setAttribute('srcset', $new_img->getAttribute('srcset'));
                    }
                    if ($new_img->hasAttribute('sizes')) {
                        $node->setAttribute('sizes', $new_img->getAttribute('sizes'));
                    }
                    if ($new_img->hasAttribute('width')) {
                        $node->setAttribute('width', $new_img->getAttribute('width'));
                    }
                    if ($new_img->hasAttribute('height')) {
                        $node->setAttribute('height', $new_img->getAttribute('height'));
                    }
                    if ($new_img->hasAttribute('alt')) {
                        $node->setAttribute('alt', $new_img->getAttribute('alt'));
                    }
                }
                
                $element_index++;
                return;
            }
            
            // Parcourir les enfants
            if ($node->hasChildNodes()) {
                $children = [];
                foreach ($node->childNodes as $child) {
                    $children[] = $child;
                }
                foreach ($children as $child) {
                    $this->update_dom_elements($child, $new_elements, $element_index);
                }
            }
        }
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
