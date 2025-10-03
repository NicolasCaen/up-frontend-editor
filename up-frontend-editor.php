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
        
        // Script principal (nouvelle architecture modulaire)
        // Utiliser frontend-editor-new.js avec type="module"
        wp_enqueue_script(
            'up-frontend-editor',
            plugin_dir_url(__FILE__) . 'assets/js/frontend-editor-new.js',
            [], // Pas de dépendances jQuery
            null,
            true
        );
        
        // Ajouter l'attribut type="module" au script
        add_filter('script_loader_tag', function($tag, $handle) {
            if ('up-frontend-editor' === $handle) {
                $tag = str_replace(' src', ' type="module" src', $tag);
            }
            return $tag;
        }, 10, 2);
        
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
                <button id="up-cancel-edit" class="up-cancel-button" <?php disabled(!$enabled); ?> >
                    <span class="dashicons dashicons-no"></span>
                    Annuler
                </button>
            </div>
        </div>
        <?php
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
        
        if (!$post_id) {
            wp_send_json_error(['message' => 'ID de post invalide']);
        }
        
        if (empty($new_title)) {
            wp_send_json_error(['message' => 'Le titre ne peut pas être vide']);
        }
        
        // Mettre à jour le titre
        $result = wp_update_post([
            'ID' => $post_id,
            'post_title' => $new_title
        ]);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
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
        }
        // Autoriser aussi srcset/sizes sur img
        if (!isset($allowed['img'])) { $allowed['img'] = []; }
        $allowed['img']['srcset'] = true;
        $allowed['img']['sizes'] = true;
        $new_content = wp_kses($raw_content, $allowed);
        
        if (!$post_id) {
            wp_send_json_error(['message' => 'ID de post invalide']);
        }
        
        // Nettoyer le contenu des attributs contenteditable
        $new_content = $this->clean_content($new_content);
        
        // Récupérer le contenu original du post
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post introuvable']);
        }
        
        $original_content = $post->post_content;
        
        // Vérifier si le contenu contient des blocs Gutenberg
        if (has_blocks($original_content)) {
            // Parser les blocs existants
            $blocks = parse_blocks($original_content);
            
            // Mettre à jour le contenu des blocs avec le nouveau contenu
            $updated_blocks = $this->update_blocks_content($blocks, $new_content);
            
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
        if ($block_name === 'core/heading' && isset($attrs['content'])) {
            // Récupérer le premier h1-h6 et son texte
            for ($lvl = 1; $lvl <= 6; $lvl++) {
                $nodes = $dom->getElementsByTagName('h' . $lvl);
                if ($nodes->length > 0) {
                    $heading = $nodes->item(0);
                    $attrs['content'] = trim($heading->textContent);
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
