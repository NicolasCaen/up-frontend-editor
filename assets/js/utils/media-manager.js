/**
 * Gestionnaire de la médiathèque WordPress
 */
export class MediaManager {
    /**
     * Ouvre la médiathèque et retourne une Promise avec l'image sélectionnée
     */
    static openMediaLibrary(options = {}) {
        return new Promise((resolve, reject) => {
            if (!wp.media) {
                reject(new Error('WordPress Media Library non disponible'));
                return;
            }

            const frame = wp.media({
                title: options.title || 'Choisir une image',
                multiple: options.multiple || false,
                library: { type: options.type || 'image' }
            });

            frame.on('select', () => {
                const attachment = frame.state().get('selection').first().toJSON();
                if (attachment && attachment.url) {
                    resolve(attachment);
                } else {
                    reject(new Error('Aucune image sélectionnée'));
                }
            });

            frame.on('close', () => {
                // Si fermé sans sélection, on ne rejette pas (l'utilisateur a annulé)
            });

            frame.open();
        });
    }

    /**
     * Extrait l'ID d'attachement depuis un élément
     */
    static getAttachmentId(element) {
        // Depuis data-attachment-id
        if (element.dataset.attachmentId) {
            return parseInt(element.dataset.attachmentId);
        }

        // Depuis la classe wp-image-X
        const classes = element.className.match(/wp-image-(\d+)/);
        if (classes && classes[1]) {
            return parseInt(classes[1]);
        }

        return null;
    }

    /**
     * Définit l'ID d'attachement sur un élément
     */
    static setAttachmentId(element, id) {
        element.dataset.attachmentId = id;
        
        // Ajouter aussi la classe wp-image-X
        element.classList.forEach(cls => {
            if (cls.startsWith('wp-image-')) {
                element.classList.remove(cls);
            }
        });
        
        if (id) {
            element.classList.add(`wp-image-${id}`);
        }
    }
}
