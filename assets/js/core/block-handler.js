/**
 * Classe de base pour tous les handlers de blocs
 */
export class BlockHandler {
    constructor(config = {}) {
        this.blockSelector = config.blockSelector || '';
        this.editableSelector = config.editableSelector || '';
        this.blockType = config.blockType || 'generic';
    }

    /**
     * Initialise le handler
     */
    init() {
        this.addEditButtons();
        this.attachEvents();
    }

    /**
     * Ajoute les boutons d'édition (à surcharger)
     */
    addEditButtons() {
        console.warn(`addEditButtons() doit être implémenté pour ${this.blockType}`);
    }

    /**
     * Attache les événements (à surcharger)
     */
    attachEvents() {
        console.warn(`attachEvents() doit être implémenté pour ${this.blockType}`);
    }

    /**
     * Trouve tous les blocs de ce type
     */
    findBlocks() {
        return document.querySelectorAll(this.blockSelector);
    }

    /**
     * Marque un changement
     */
    markAsChanged() {
        window.UPFrontendEditor?.markChanges();
    }

    /**
     * Log de débogage
     */
    log(...args) {
        if (window.UPFrontendEditor?.debug) {
            console.log(`[${this.blockType}]`, ...args);
        }
    }
}
