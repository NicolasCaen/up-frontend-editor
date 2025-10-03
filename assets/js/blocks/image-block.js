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
        const images = this.findBlocks();
        this.log(`${images.length} image(s) trouvée(s)`);

        images.forEach(img => {
            // Ne pas ajouter si dans un bloc cover ou déjà wrappé
            if (img.closest('.wp-block-cover') || img.parentElement.classList.contains('up-image-wrapper')) {
                return;
            }

            // Wrapper l'image
            const wrapper = document.createElement('span');
            wrapper.className = 'up-image-wrapper';
            img.parentNode.insertBefore(wrapper, img);
            wrapper.appendChild(img);

            // Ajouter le bouton
            const button = this.createEditButton();
            wrapper.appendChild(button);
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
        document.addEventListener('click', async (e) => {
            if (e.target.closest('.up-image-edit-btn')) {
                e.preventDefault();
                e.stopPropagation();

                const button = e.target.closest('.up-image-edit-btn');
                const img = button.parentElement.querySelector('img');

                await this.handleImageChange(img);
            }
        });
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
