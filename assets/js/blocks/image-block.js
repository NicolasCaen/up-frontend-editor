import { BlockHandler } from '../core/block-handler.js';
import { MediaManager } from '../utils/media-manager.js';

/**
 * Handler pour les blocs Image
 */
export class ImageBlockHandler extends BlockHandler {
    constructor() {
        super({
            blockSelector: '.up-editable-content img.up-editable-image',
            blockType: 'image'
        });
    }

    /**
     * Ajoute les boutons d'édition sur les images
     */
    addEditButtons() {
        // Crée un seul bouton flottant réutilisable pour toutes les images
        this.ensureOverlayButton();
        const images = this.findBlocks();
        this.log(`${images.length} image(s) trouvée(s)`);

        images.forEach(img => {
            if (img.closest('.wp-block-cover')) return; // la cover a son propre bouton
            img.addEventListener('mouseenter', () => {
                this.currentTarget = img;
                this.repositionOverlay(img);
                this.overlayBtn.classList.add('is-visible');
            });
            img.addEventListener('mouseleave', (e) => {
                // Si on survole le bouton, ne pas masquer
                const related = e.relatedTarget;
                if (related === this.overlayBtn || this.overlayBtn.contains(related)) return;
                this.hideOverlay();
            });
        });
    }

    /**
     * Crée le bouton d'édition
     */
    createEditButton() {
        const button = document.createElement('button');
        button.className = 'up-image-edit-btn';
        button.title = "Changer l'image";
        button.innerHTML = '<span class="dashicons dashicons-format-image"></span>';
        return button;
    }

    /**
     * Attache les événements
     */
    attachEvents() {
        // Click sur le bouton flottant
        this.ensureOverlayButton();
        this.overlayBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!this.currentTarget) return;
            await this.handleImageChange(this.currentTarget);
            // Repositionner après changement (dimensions possibles)
            this.repositionOverlay(this.currentTarget);
        });

        // Cacher quand on sort du bouton
        this.overlayBtn.addEventListener('mouseleave', () => {
            this.hideOverlay();
        });

        // Repositionner sur scroll/resize si visible
        const repositionIfVisible = () => {
            if (this.overlayBtn.classList.contains('is-visible') && this.currentTarget) {
                this.repositionOverlay(this.currentTarget);
            }
        };
        window.addEventListener('scroll', repositionIfVisible, true);
        window.addEventListener('resize', repositionIfVisible);
    }

    ensureOverlayButton() {
        if (this.overlayBtn) return;
        const btn = this.createEditButton();
        btn.classList.add('up-floating');
        btn.style.position = 'fixed';
        btn.style.top = '0px';
        btn.style.left = '0px';
        btn.style.zIndex = '100000';
        btn.classList.remove('up-image-edit-btn');
        btn.classList.add('up-image-edit-btn'); // normaliser la classe si CSS la cible
        document.body.appendChild(btn);
        this.overlayBtn = btn;
    }

    repositionOverlay(img) {
        if (!this.overlayBtn || !img) return;
        const rect = img.getBoundingClientRect();
        const offset = 8; // marge intérieure
        const btnSize = 36; // cohérent avec CSS
        const top = Math.max(0, rect.top + offset);
        const left = Math.max(0, rect.right - btnSize - offset);
        this.overlayBtn.style.top = `${top}px`;
        this.overlayBtn.style.left = `${left}px`;
    }

    hideOverlay() {
        if (!this.overlayBtn) return;
        this.overlayBtn.classList.remove('is-visible');
        this.currentTarget = null;
    }

    /**
     * Gère le changement d'image
     */
    async handleImageChange(img) {
        try {
            const attachment = await MediaManager.openMediaLibrary({
                title: "Choisir une image"
            });

            this.updateImage(img, attachment);
            this.markAsChanged();

        } catch (error) {
            if (error.message !== 'Aucune image sélectionnée') {
                console.error('Erreur lors du changement d\'image:', error);
            }
        }
    }

    /**
     * Met à jour une image
     */
    updateImage(img, attachment) {
        img.src = attachment.url;

        // Mettre à jour srcset si disponible
        if (attachment.sizes) {
            const srcset = Object.values(attachment.sizes)
                .map(size => `${size.url} ${size.width}w`)
                .join(', ');
            img.srcset = srcset;
            img.sizes = '(max-width: 800px) 100vw, 800px';
        }

        // Mettre à jour les dimensions
        if (attachment.width && attachment.height) {
            img.width = attachment.width;
            img.height = attachment.height;
        }

        // Mettre à jour l'alt si disponible
        if (attachment.alt) {
            img.alt = attachment.alt;
        }

        MediaManager.setAttachmentId(img, attachment.id);

        this.log('Image mise à jour', attachment);
    }
}
