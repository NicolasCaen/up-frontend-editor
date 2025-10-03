import { BlockHandler } from '../core/block-handler.js';

/**
 * Handler pour les blocs de texte éditables (paragraphes, titres, listes, etc.)
 */
export class TextBlocksHandler extends BlockHandler {
    constructor() {
        super({
            editableSelector: '.up-editable',
            blockType: 'text'
        });
    }

    /**
     * Pas de boutons à ajouter pour les blocs de texte
     */
    addEditButtons() {
        // Les éléments texte sont déjà éditables via contenteditable
        const editables = document.querySelectorAll(this.editableSelector);
        this.log(`${editables.length} élément(s) de texte éditable(s)`);
    }

    /**
     * Attache les événements pour les blocs de texte
     */
    attachEvents() {
        // Détecter les changements
        document.addEventListener('input', (e) => {
            if (e.target && e.target.matches && e.target.matches(this.editableSelector)) {
                this.markAsChanged();
            }
        });

        // Gérer les raccourcis clavier (Ctrl+B, Ctrl+I, etc.)
        document.addEventListener('keydown', (e) => {
            if (e.target && e.target.matches && e.target.matches(this.editableSelector)) {
                this.handleKeyboardShortcuts(e);
            }
        });

        // Gérer le collage (nettoyer le formatage)
        document.addEventListener('paste', (e) => {
            if (e.target && e.target.matches && e.target.matches(this.editableSelector)) {
                this.handlePaste(e);
            }
        });

        // Effets visuels au survol et focus
        this.attachVisualEffects();
    }

    /**
     * Gère les raccourcis clavier
     */
    handleKeyboardShortcuts(e) {
        if (e.ctrlKey || e.metaKey) {
            const key = e.key.toLowerCase();
            if (['b', 'i', 'u'].includes(key)) {
                e.preventDefault();
                const command = key === 'b' ? 'bold' : (key === 'i' ? 'italic' : 'underline');
                document.execCommand(command);
            }
        }
    }

    /**
     * Gère le collage de texte
     */
    handlePaste(e) {
        e.preventDefault();
        
        // Récupérer le texte sans formatage
        const text = (e.originalEvent || e).clipboardData.getData('text/plain');
        
        // Insérer le texte
        document.execCommand('insertText', false, text);
    }

    /**
     * Attache les effets visuels
     */
    attachVisualEffects() {
        // Survol
        document.addEventListener('mouseenter', (e) => {
            if (e.target && e.target.matches && e.target.matches(this.editableSelector)) {
                e.target.classList.add('up-hover');
            }
        }, true);

        document.addEventListener('mouseleave', (e) => {
            if (e.target && e.target.matches && e.target.matches(this.editableSelector)) {
                e.target.classList.remove('up-hover');
            }
        }, true);

        // Focus
        document.addEventListener('focus', (e) => {
            if (e.target && e.target.matches && e.target.matches(this.editableSelector)) {
                e.target.classList.add('up-focused');
            }
        }, true);

        document.addEventListener('blur', (e) => {
            if (e.target && e.target.matches && e.target.matches(this.editableSelector)) {
                e.target.classList.remove('up-focused');
            }
        }, true);
    }
}
