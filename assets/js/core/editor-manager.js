import { AjaxHandler } from '../utils/ajax-handler.js';

/**
 * Gestionnaire principal de l'éditeur
 */
export class EditorManager {
    constructor(config) {
        this.config = config;
        this.ajax = new AjaxHandler(config);
        this.blockHandlers = [];
        this.hasChanges = false;
        this.originalContent = '';
        this.debug = config.debug || false;

        // Exposer globalement pour les handlers
        window.UPFrontendEditor = this;
    }

    /**
     * Enregistre un handler de bloc
     */
    registerBlockHandler(handler) {
        this.blockHandlers.push(handler);
        this.log('Handler enregistré:', handler.blockType);
    }

    /**
     * Initialise l'éditeur
     */
    init() {
        this.log('Initialisation de l\'éditeur...');

        // Vérifier si l'utilisateur peut éditer
        if (!this.config.canEdit) {
            this.log('Utilisateur non autorisé');
            return;
        }

        // Vérifier si le mode édition est activé
        if (!this.config.enabled) {
            this.log('Mode édition désactivé');
            this.disableEditButtons();
            return;
        }

        // Initialiser tous les handlers
        this.initializeHandlers();

        // Sauvegarder le contenu original
        this.saveOriginalContent();

        // Attacher les événements globaux
        this.attachGlobalEvents();

        this.log('Éditeur initialisé avec succès');
    }

    /**
     * Initialise tous les handlers de blocs
     */
    initializeHandlers() {
        // Petit délai pour s'assurer que le DOM est prêt
        setTimeout(() => {
            this.blockHandlers.forEach(handler => {
                handler.init();
            });
        }, 100);
    }

    /**
     * Sauvegarde le contenu original
     */
    saveOriginalContent() {
        const contentElement = document.querySelector('.up-editable-content');
        if (contentElement) {
            this.originalContent = contentElement.innerHTML;
        }
    }

    /**
     * Attache les événements globaux
     */
    attachGlobalEvents() {
        // Toggle d'activation
        this.attachToggleEvent();

        // Bouton Enregistrer
        this.attachSaveEvent();

        // Bouton Annuler
        this.attachCancelEvent();

        // Raccourcis clavier
        this.attachKeyboardShortcuts();

        // Avertissement avant de quitter
        this.attachBeforeUnload();
    }

    /**
     * Gère le toggle d'activation
     */
    attachToggleEvent() {
        const toggle = document.getElementById('up-fe-toggle');
        if (toggle) {
            toggle.addEventListener('change', (e) => {
                const enabled = e.target.checked ? '1' : '0';
                const expires = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toUTCString();
                document.cookie = `up_fe_enabled=${enabled}; expires=${expires}; path=/`;
                window.location.reload();
            });
        }
    }

    /**
     * Gère le bouton Enregistrer
     */
    attachSaveEvent() {
        const saveButton = document.getElementById('up-save-content');
        if (saveButton) {
            saveButton.addEventListener('click', () => this.save());
        }
    }

    /**
     * Gère le bouton Annuler
     */
    attachCancelEvent() {
        const cancelButton = document.getElementById('up-cancel-edit');
        if (cancelButton) {
            cancelButton.addEventListener('click', () => this.cancel());
        }
    }

    /**
     * Gère les raccourcis clavier
     */
    attachKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl+S ou Cmd+S pour sauvegarder
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                this.save();
            }

            // Escape pour annuler
            if (e.key === 'Escape') {
                this.cancel();
            }
        });
    }

    /**
     * Avertissement avant de quitter
     */
    attachBeforeUnload() {
        window.addEventListener('beforeunload', (e) => {
            if (this.hasChanges) {
                const message = 'Vous avez des modifications non enregistrées. Voulez-vous vraiment quitter ?';
                e.returnValue = message;
                return message;
            }
        });
    }

    /**
     * Marque qu'il y a des changements
     */
    markChanges() {
        this.hasChanges = true;
        const saveButton = document.getElementById('up-save-content');
        if (saveButton) {
            saveButton.classList.add('has-changes');
        }
    }

    /**
     * Sauvegarde les modifications
     */
    async save() {
        if (!this.hasChanges) {
            this.showNotice('Aucune modification à enregistrer', 'info');
            return;
        }

        const saveButton = document.getElementById('up-save-content');
        if (saveButton) {
            saveButton.disabled = true;
            saveButton.classList.add('saving');
            const icon = saveButton.querySelector('span');
            if (icon) {
                icon.classList.remove('dashicons-yes');
                icon.classList.add('dashicons-update');
            }
        }

        try {
            const promises = [];

            // Sauvegarder le titre si modifié
            const titleHandler = this.blockHandlers.find(h => h.blockType === 'title');
            if (titleHandler && titleHandler.hasChanged()) {
                const newTitle = titleHandler.getCurrentTitle();
                promises.push(this.ajax.saveTitle(newTitle));
            }

            // Sauvegarder le contenu
            const contentElement = document.querySelector('.up-editable-content');
            if (contentElement) {
                const content = contentElement.innerHTML;
                promises.push(this.ajax.saveContent(content));
            }

            await Promise.all(promises);

            this.showNotice('Modifications sauvegardées avec succès', 'success');
            this.hasChanges = false;
            this.saveOriginalContent();

            if (saveButton) {
                saveButton.classList.remove('has-changes');
            }

        } catch (error) {
            this.showNotice(error.message || 'Erreur lors de la sauvegarde', 'error');
        } finally {
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.classList.remove('saving');
                const icon = saveButton.querySelector('span');
                if (icon) {
                    icon.classList.remove('dashicons-update');
                    icon.classList.add('dashicons-yes');
                }
            }
        }
    }

    /**
     * Annule les modifications
     */
    cancel() {
        if (!this.hasChanges) {
            this.showNotice('Aucune modification à annuler', 'info');
            return;
        }

        if (confirm('Voulez-vous vraiment annuler vos modifications ?')) {
            // Restaurer le contenu
            const contentElement = document.querySelector('.up-editable-content');
            if (contentElement) {
                contentElement.innerHTML = this.originalContent;
            }

            // Restaurer le titre
            const titleHandler = this.blockHandlers.find(h => h.blockType === 'title');
            if (titleHandler) {
                titleHandler.restore();
            }

            // Ré-initialiser les handlers
            this.initializeHandlers();

            this.hasChanges = false;
            const saveButton = document.getElementById('up-save-content');
            if (saveButton) {
                saveButton.classList.remove('has-changes');
            }

            this.showNotice('Modifications annulées', 'info');
        }
    }

    /**
     * Affiche une notification
     */
    showNotice(message, type = 'info') {
        // Supprimer les anciennes notifications
        document.querySelectorAll('.up-editor-notice').forEach(n => n.remove());

        const notice = document.createElement('div');
        notice.className = `up-editor-notice up-notice-${type}`;
        notice.textContent = message;

        document.body.appendChild(notice);

        // Animation d'entrée
        setTimeout(() => notice.classList.add('show'), 10);

        // Retirer après 3 secondes
        setTimeout(() => {
            notice.classList.remove('show');
            setTimeout(() => notice.remove(), 300);
        }, 3000);
    }

    /**
     * Désactive les boutons d'édition
     */
    disableEditButtons() {
        const buttons = document.querySelectorAll('#up-save-content, #up-cancel-edit');
        buttons.forEach(btn => btn.disabled = true);
    }

    /**
     * Log de débogage
     */
    log(...args) {
        if (this.debug) {
            console.log('[EditorManager]', ...args);
        }
    }
}
