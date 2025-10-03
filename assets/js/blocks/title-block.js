import { BlockHandler } from '../core/block-handler.js';

/**
 * Handler pour le titre de la page
 */
export class TitleBlockHandler extends BlockHandler {
    constructor() {
        super({
            editableSelector: '.up-editable-title',
            blockType: 'title'
        });
        this.originalTitle = '';
    }

    /**
     * Initialise et sauvegarde le titre original
     */
    init() {
        super.init();
        const titleElement = document.querySelector(this.editableSelector);
        if (titleElement) {
            this.originalTitle = titleElement.textContent.trim();
            this.log('Titre original sauvegardé:', this.originalTitle);
        }
    }

    /**
     * Pas de boutons pour le titre
     */
    addEditButtons() {
        const title = document.querySelector(this.editableSelector);
        if (title) {
            this.log('Titre de page éditable trouvé');
        }
    }

    /**
     * Attache les événements
     */
    attachEvents() {
        // Détecter les changements
        document.addEventListener('input', (e) => {
            if (e.target && e.target.matches && e.target.matches(this.editableSelector)) {
                this.markAsChanged();
            }
        });

        // Gérer le collage
        document.addEventListener('paste', (e) => {
            if (e.target && e.target.matches && e.target.matches(this.editableSelector)) {
                e.preventDefault();
                const text = (e.originalEvent || e).clipboardData.getData('text/plain');
                document.execCommand('insertText', false, text);
            }
        });
    }

    /**
     * Récupère le titre actuel
     */
    getCurrentTitle() {
        const titleElement = document.querySelector(this.editableSelector);
        return titleElement ? titleElement.textContent.trim() : '';
    }

    /**
     * Vérifie si le titre a changé
     */
    hasChanged() {
        return this.getCurrentTitle() !== this.originalTitle;
    }

    /**
     * Restaure le titre original
     */
    restore() {
        const titleElement = document.querySelector(this.editableSelector);
        if (titleElement && this.originalTitle) {
            titleElement.textContent = this.originalTitle;
        }
    }
}
