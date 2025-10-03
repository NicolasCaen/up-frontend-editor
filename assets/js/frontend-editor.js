/**
 * UP Frontend Editor - JavaScript
 * Gère l'édition en front-end avec contenteditable
 */

(function($) {
    'use strict';
    
    let hasChanges = false;
    let originalContent = '';
    
    $(document).ready(function() {
        
        // Vérifier si l'utilisateur peut éditer
        if (!upFrontendEditor || !upFrontendEditor.canEdit) {
            return;
        }

        // Gestion du toggle d'activation (cookie + reload)
        const $toggle = $('#up-fe-toggle');
        if ($toggle.length) {
            $toggle.on('change', function() {
                const enabled = $(this).is(':checked') ? '1' : '0';
                // Définir cookie pour 7 jours, chemin global
                const expires = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toUTCString();
                document.cookie = 'up_fe_enabled=' + enabled + '; expires=' + expires + '; path=/';
                // Recharger pour appliquer serveur (wrapper + attributes)
                window.location.reload();
            });
        }

        // Si le mode n'est pas activé, ne pas initialiser l'édition
        if (!upFrontendEditor.enabled) {
            // Désactiver les boutons d'édition
            $('#up-save-content, #up-cancel-edit').prop('disabled', true);
            return;
        }

        // Sauvegarder le contenu original
        const $editableContent = $('.up-editable-content');
        if ($editableContent.length) {
            originalContent = $editableContent.html();
        }
        // Détecter les changements
        $('.up-editable').on('input', function() {
            hasChanges = true;
            $('#up-save-content').addClass('has-changes');
        });
        
        // Empêcher le comportement par défaut de certaines touches
        $('.up-editable').on('keydown', function(e) {
            // Empêcher Ctrl+B, Ctrl+I, etc. de créer des balises non désirées
            if (e.ctrlKey || e.metaKey) {
                const key = e.key.toLowerCase();
                if (['b', 'i', 'u'].includes(key)) {
                    e.preventDefault();
                    document.execCommand(key === 'b' ? 'bold' : (key === 'i' ? 'italic' : 'underline'));
                }
            }
        });
        
        // Gérer le collage de texte (nettoyer le formatage)
        $('.up-editable').on('paste', function(e) {
            e.preventDefault();
            
            // Récupérer le texte sans formatage
            const text = (e.originalEvent || e).clipboardData.getData('text/plain');
            
            // Insérer le texte
            document.execCommand('insertText', false, text);
        });
        
        // Cliquer sur une image pour la remplacer via la médiathèque
        function openMediaFrame($img) {
            const frame = wp.media({
                title: 'Choisir une image',
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                if (!attachment || !attachment.url) return;
                // Mettre à jour src et srcset si dispo
                $img.attr('src', attachment.url);
                if (attachment.sizes) {
                    const srcset = Object.values(attachment.sizes)
                        .map(s => s.url + ' ' + s.width + 'w')
                        .join(', ');
                    $img.attr('srcset', srcset);
                    // taille responsive par défaut
                    $img.attr('sizes', '(max-width: 800px) 100vw, 800px');
                }
                if (attachment.width && attachment.height) {
                    $img.attr('width', attachment.width);
                    $img.attr('height', attachment.height);
                }
                $img.attr('data-attachment-id', attachment.id || '');
                hasChanges = true;
                $('#up-save-content').addClass('has-changes');
            });
            frame.open();
        }

        // Délégation de click sur les images éditables
        $(document).on('click', '.up-editable-content img.up-editable-image', function(e) {
            e.preventDefault();
            const $img = $(this);
            openMediaFrame($img);
        });

        // Bouton Enregistrer
        $('#up-save-content').on('click', function() {
            const $button = $(this);
            
            if (!hasChanges) {
                showNotice('Aucune modification à enregistrer', 'info');
                return;
            }
            
            // Désactiver le bouton pendant la sauvegarde
            $button.prop('disabled', true).addClass('saving');
            $button.find('span').removeClass('dashicons-yes').addClass('dashicons-update');
            
            // Récupérer le contenu modifié
            const content = $editableContent.html();
            
            // Envoyer via AJAX
            $.ajax({
                url: upFrontendEditor.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'up_save_frontend_content',
                    nonce: upFrontendEditor.nonce,
                    post_id: upFrontendEditor.postId,
                    content: content
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        hasChanges = false;
                        $button.removeClass('has-changes');
                        originalContent = content;
                    } else {
                        showNotice(response.data.message || 'Erreur lors de la sauvegarde', 'error');
                    }
                },
                error: function() {
                    showNotice('Erreur de connexion', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).removeClass('saving');
                    $button.find('span').removeClass('dashicons-update').addClass('dashicons-yes');
                }
            });
        });
        
        // Bouton Annuler
        $('#up-cancel-edit').on('click', function() {
            if (hasChanges) {
                if (confirm('Voulez-vous vraiment annuler vos modifications ?')) {
                    $editableContent.html(originalContent);
                    hasChanges = false;
                    $('#up-save-content').removeClass('has-changes');
                    showNotice('Modifications annulées', 'info');
                }
            } else {
                showNotice('Aucune modification à annuler', 'info');
            }
        });
        
        // Avertir avant de quitter si des changements non sauvegardés
        $(window).on('beforeunload', function(e) {
            if (hasChanges) {
                const message = 'Vous avez des modifications non enregistrées. Voulez-vous vraiment quitter ?';
                e.returnValue = message;
                return message;
            }
        });
        
        // Raccourcis clavier
        $(document).on('keydown', function(e) {
            // Ctrl+S ou Cmd+S pour sauvegarder
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                $('#up-save-content').trigger('click');
            }
            
            // Escape pour annuler
            if (e.key === 'Escape') {
                $('#up-cancel-edit').trigger('click');
            }
        });
        
        // Fonction pour afficher les notifications
        function showNotice(message, type) {
            // Supprimer les anciennes notifications
            $('.up-editor-notice').remove();
            
            const noticeClass = 'up-editor-notice up-notice-' + type;
            const notice = $('<div class="' + noticeClass + '">' + message + '</div>');
            
            $('body').append(notice);
            
            // Animation d'entrée
            setTimeout(function() {
                notice.addClass('show');
            }, 10);
            
            // Retirer après 3 secondes
            setTimeout(function() {
                notice.removeClass('show');
                setTimeout(function() {
                    notice.remove();
                }, 300);
            }, 3000);
        }
        
        // Ajouter un indicateur visuel sur les éléments éditables au survol
        $('.up-editable').on('mouseenter', function() {
            $(this).addClass('up-hover');
        }).on('mouseleave', function() {
            $(this).removeClass('up-hover');
        });
        
        // Focus sur un élément éditable
        $('.up-editable').on('focus', function() {
            $(this).addClass('up-focused');
        }).on('blur', function() {
            $(this).removeClass('up-focused');
        });
        
    });
    
})(jQuery);
