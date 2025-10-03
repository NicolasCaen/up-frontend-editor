/**
 * UP Frontend Editor - Point d'entrée principal (Architecture modulaire)
 * Utilise les modules ES6 natifs
 */

import { EditorManager } from './core/editor-manager.js';
import { CoverBlockHandler } from './blocks/cover-block.js';
import { ImageBlockHandler } from './blocks/image-block.js';
import { TextBlocksHandler } from './blocks/text-blocks.js';
import { TitleBlockHandler } from './blocks/title-block.js';

/**
 * Initialisation au chargement du DOM
 */
document.addEventListener('DOMContentLoaded', () => {
    // Vérifier que la configuration est disponible
    if (typeof upFrontendEditor === 'undefined') {
        console.error('UP Frontend Editor: Configuration non disponible');
        return;
    }

    // Créer le gestionnaire principal
    const editor = new EditorManager({
        ajaxUrl: upFrontendEditor.ajaxUrl,
        nonce: upFrontendEditor.nonce,
        postId: upFrontendEditor.postId,
        canEdit: upFrontendEditor.canEdit,
        enabled: upFrontendEditor.enabled,
        debug: true // Mettre à false en production
    });

    // Enregistrer les handlers de blocs
    editor.registerBlockHandler(new TitleBlockHandler());
    editor.registerBlockHandler(new TextBlocksHandler());
    editor.registerBlockHandler(new ImageBlockHandler());
    editor.registerBlockHandler(new CoverBlockHandler());

    // Initialiser l'éditeur
    editor.init();

    // Log pour le débogage
    console.log('UP Frontend Editor initialisé avec succès');
});
