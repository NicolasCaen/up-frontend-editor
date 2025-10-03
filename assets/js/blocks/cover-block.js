import { BlockHandler } from '../core/block-handler.js';
import { MediaManager } from '../utils/media-manager.js';

/**
 * Handler pour les blocs Cover (bannières)
 */
export class CoverBlockHandler extends BlockHandler {
    constructor() {
        super({
            blockSelector: '.up-editable-content .wp-block-cover',
            blockType: 'cover'
        });
    }

    /**
     * Ajoute les boutons d'édition sur les bannières
     */
    addEditButtons() {
        const covers = this.findBlocks();
        this.log(`${covers.length} bloc(s) cover trouvé(s)`);

        covers.forEach(cover => {
            // Vérifier s'il y a une image
            const hasImage = this.hasBackgroundImage(cover);
            
            if (hasImage && !cover.classList.contains('up-has-editable-image')) {
                cover.classList.add('up-has-editable-image');
                
                // Créer le bouton
                const button = this.createEditButton();
                cover.insertBefore(button, cover.firstChild);
                
                this.log('Bouton ajouté sur cover');
            }
        });
    }

    /**
     * Vérifie si le bloc cover a une image de fond
     */
    hasBackgroundImage(cover) {
        // Chercher un div avec background-image
        const bgDiv = cover.querySelector('.wp-block-cover__image-background');
        if (bgDiv) {
            const bgImage = window.getComputedStyle(bgDiv).backgroundImage;
            return bgImage && bgImage !== 'none';
        }

        // Chercher une balise img
        const img = cover.querySelector('img');
        return !!img;
    }

    /**
     * Crée le bouton d'édition
     */
    createEditButton() {
        const button = document.createElement('button');
        button.className = 'up-cover-edit-btn';
        button.title = "Changer l'image de bannière";
        button.innerHTML = '<span class="dashicons dashicons-format-image"></span>';
        return button;
    }

    /**
     * Attache les événements
     */
    attachEvents() {
        // Délégation d'événements pour les boutons
        document.addEventListener('click', async (e) => {
            if (e.target.closest('.up-cover-edit-btn')) {
                e.preventDefault();
                e.stopPropagation();
                
                const button = e.target.closest('.up-cover-edit-btn');
                const cover = button.closest('.wp-block-cover');
                
                await this.handleImageChange(cover);
            }
        });
    }

    /**
     * Gère le changement d'image
     */
    async handleImageChange(cover) {
        try {
            const attachment = await MediaManager.openMediaLibrary({
                title: "Choisir une image de bannière"
            });

            this.updateCoverImage(cover, attachment);
            this.markAsChanged();
            
        } catch (error) {
            if (error.message !== 'Aucune image sélectionnée') {
                console.error('Erreur lors du changement d\'image:', error);
            }
        }
    }

    /**
     * Met à jour l'image du bloc cover
     */
    updateCoverImage(cover, attachment) {
        // Chercher le div avec background-image
        let bgDiv = cover.querySelector('.wp-block-cover__image-background');

        if (bgDiv) {
            // Mettre à jour le background-image
            bgDiv.style.backgroundImage = `url(${attachment.url})`;
            MediaManager.setAttachmentId(bgDiv, attachment.id);
        } else {
            // Chercher une balise img
            let img = cover.querySelector('img');

            if (!img) {
                // Créer un div avec background-image
                bgDiv = document.createElement('div');
                bgDiv.className = 'wp-block-cover__image-background has-parallax';
                bgDiv.style.backgroundPosition = '50% 50%';
                bgDiv.style.backgroundImage = `url(${attachment.url})`;
                MediaManager.setAttachmentId(bgDiv, attachment.id);
                cover.insertBefore(bgDiv, cover.firstChild);
            } else {
                // Mettre à jour l'image existante
                img.src = attachment.url;
                MediaManager.setAttachmentId(img, attachment.id);
            }
        }

        this.log('Image de cover mise à jour', attachment);
    }
}
