/**
 * Gestionnaire des requêtes AJAX
 */
export class AjaxHandler {
    constructor(config) {
        this.ajaxUrl = config.ajaxUrl;
        this.nonce = config.nonce;
        this.postId = config.postId;
    }

    /**
     * Envoie une requête AJAX
     */
    async request(action, data = {}) {
        const formData = new URLSearchParams();
        formData.append('action', action);
        formData.append('nonce', this.nonce);
        formData.append('post_id', this.postId);

        // Ajouter les données supplémentaires
        Object.keys(data).forEach(key => {
            formData.append(key, data[key]);
        });

        try {
            const response = await fetch(this.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString(),
                credentials: 'same-origin'
            });

            const result = await response.json();

            if (result.success) {
                return result.data;
            } else {
                throw new Error(result.data?.message || 'Erreur lors de la requête');
            }
        } catch (error) {
            console.error('Erreur AJAX:', error);
            throw error;
        }
    }

    /**
     * Sauvegarde le contenu
     */
    async saveContent(content) {
        return this.request('up_save_frontend_content', { content });
    }

    /**
     * Sauvegarde le titre
     */
    async saveTitle(title) {
        return this.request('up_save_page_title', { title });
    }
}
