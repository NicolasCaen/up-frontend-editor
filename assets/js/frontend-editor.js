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

        // Ajouter des boutons d'édition sur les images AVANT de sauvegarder le contenu original
        // Utiliser un petit délai pour s'assurer que le DOM est complètement chargé
        setTimeout(function() {
            addImageEditButtons();
        }, 100);
        
        // Sauvegarder le contenu original
        const $editableContent = $('.up-editable-content');
        if ($editableContent.length) {
            originalContent = $editableContent.html();
        }
        
        let originalTitle = '';
        const $editableTitle = $('.up-editable-title');
        if ($editableTitle.length) {
            originalTitle = $editableTitle.text();
        }
        
        // Détecter les changements sur le contenu (utiliser délégation)
        $(document).on('input', '.up-editable', function() {
            hasChanges = true;
            $('#up-save-content').addClass('has-changes');
        });
        
        // Détecter les changements sur le titre
        $(document).on('input', '.up-editable-title', function() {
            hasChanges = true;
            $('#up-save-content').addClass('has-changes');
        });
        
        // Empêcher le comportement par défaut de certaines touches
        $(document).on('keydown', '.up-editable', function(e) {
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
        $(document).on('paste', '.up-editable, .up-editable-title', function(e) {
            e.preventDefault();
            
            // Récupérer le texte sans formatage
            const text = (e.originalEvent || e).clipboardData.getData('text/plain');
            
            // Insérer le texte
            document.execCommand('insertText', false, text);
        });
        
        // Fonction pour ajouter des boutons d'édition sur les images
        function addImageEditButtons() {
            console.log('UP Frontend Editor: Ajout des boutons d\'édition...');
            
            // Images normales (blocs image, etc.)
            const $images = $('.up-editable-content img.up-editable-image');
            console.log('Images trouvées:', $images.length);
            
            $images.each(function() {
                const $img = $(this);
                
                // Ne pas ajouter si déjà wrappé ou si c'est dans un bloc cover
                if ($img.parent().hasClass('up-image-wrapper') || $img.closest('.wp-block-cover').length > 0) {
                    return;
                }
                
                // Wrapper l'image
                $img.wrap('<span class="up-image-wrapper"></span>');
                
                // Ajouter le bouton d'édition
                const $btn = $('<button class="up-image-edit-btn" title="Changer l\'image"><span class="dashicons dashicons-format-image"></span></button>');
                $img.parent().append($btn);
            });
            
            // Bannières (blocs cover)
            const $covers = $('.up-editable-content .wp-block-cover');
            console.log('Blocs cover trouvés:', $covers.length);
            
            $covers.each(function() {
                const $cover = $(this);
                
                // Vérifier s'il y a une image de fond (img tag ou div avec background-image)
                const hasImg = $cover.find('img').length > 0;
                const $bgDiv = $cover.find('.wp-block-cover__image-background');
                const hasBgDiv = $bgDiv.length > 0 && $bgDiv.css('background-image') !== 'none';
                const hasBg = $cover.css('background-image') !== 'none';
                const hasImage = hasImg || hasBgDiv || hasBg;
                
                console.log('Cover:', {
                    hasImg: hasImg,
                    hasBgDiv: hasBgDiv,
                    hasBg: hasBg,
                    hasImage: hasImage,
                    alreadyHasBtn: $cover.hasClass('up-has-editable-image')
                });
                
                if (hasImage && !$cover.hasClass('up-has-editable-image')) {
                    $cover.addClass('up-has-editable-image');
                    
                    // Ajouter le bouton d'édition
                    const $btn = $('<button class="up-cover-edit-btn" title="Changer l\'image de bannière"><span class="dashicons dashicons-format-image"></span></button>');
                    $cover.prepend($btn);
                    console.log('Bouton ajouté sur cover');
                }
            });
            
            console.log('Boutons d\'édition ajoutés avec succès');
            
            // Test : rendre les boutons visibles en permanence pour le débogage
            // Décommenter la ligne suivante pour voir les boutons en permanence
            // $('.up-cover-edit-btn, .up-image-edit-btn').css('opacity', '1');
        }
        
        // Ouvrir la médiathèque pour remplacer une image
        function openMediaFrame($img, isCoverBlock = false) {
            const frame = wp.media({
                title: isCoverBlock ? 'Choisir une image de bannière' : 'Choisir une image',
                multiple: false,
                library: { type: 'image' }
            });
            
            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                if (!attachment || !attachment.url) return;
                
                if (isCoverBlock) {
                    // Pour les blocs cover, mettre à jour l'image de fond
                    const $cover = $img.closest('.wp-block-cover');
                    
                    // Chercher le div avec background-image
                    let $bgDiv = $cover.find('.wp-block-cover__image-background');
                    
                    if ($bgDiv.length > 0) {
                        // Mettre à jour le background-image du div
                        $bgDiv.css('background-image', 'url(' + attachment.url + ')');
                        $bgDiv.attr('data-attachment-id', attachment.id || '');
                        
                        // Ajouter la classe wp-image-X si on a un ID
                        if (attachment.id) {
                            $bgDiv.removeClass(function(index, className) {
                                return (className.match(/(^|\s)wp-image-\S+/g) || []).join(' ');
                            });
                            $bgDiv.addClass('wp-image-' + attachment.id);
                        }
                    } else {
                        // Sinon chercher une balise img
                        let $coverImg = $cover.find('img').first();
                        
                        if ($coverImg.length === 0) {
                            // Créer un div avec background-image
                            $bgDiv = $('<div class="wp-block-cover__image-background wp-image-' + attachment.id + ' has-parallax" style="background-position:50% 50%;background-image:url(' + attachment.url + ')"></div>');
                            $bgDiv.attr('data-attachment-id', attachment.id || '');
                            $cover.prepend($bgDiv);
                        } else {
                            // Mettre à jour l'image existante
                            $coverImg.attr('src', attachment.url);
                            $coverImg.attr('data-attachment-id', attachment.id || '');
                        }
                    }
                } else {
                    // Pour les images normales
                    $img.attr('src', attachment.url);
                    
                    if (attachment.sizes) {
                        const srcset = Object.values(attachment.sizes)
                            .map(s => s.url + ' ' + s.width + 'w')
                            .join(', ');
                        $img.attr('srcset', srcset);
                        $img.attr('sizes', '(max-width: 800px) 100vw, 800px');
                    }
                    
                    if (attachment.width && attachment.height) {
                        $img.attr('width', attachment.width);
                        $img.attr('height', attachment.height);
                    }
                    
                    $img.attr('data-attachment-id', attachment.id || '');
                }
                
                hasChanges = true;
                $('#up-save-content').addClass('has-changes');
            });
            
            frame.open();
        }

        // Click sur le bouton d'édition d'image normale
        $(document).on('click', '.up-image-edit-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $img = $(this).siblings('img');
            openMediaFrame($img, false);
        });
        
        // Click sur le bouton d'édition de bannière
        $(document).on('click', '.up-cover-edit-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $cover = $(this).closest('.wp-block-cover');
            openMediaFrame($cover, true);
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
            
            // Récupérer le titre modifié
            const $title = $('.up-editable-title');
            const newTitle = $title.length ? $title.text().trim() : '';
            
            let savePromises = [];
            
            // Sauvegarder le titre si modifié
            if (newTitle && newTitle !== originalTitle) {
                const titlePromise = $.ajax({
                    url: upFrontendEditor.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'up_save_page_title',
                        nonce: upFrontendEditor.nonce,
                        post_id: upFrontendEditor.postId,
                        title: newTitle
                    }
                });
                savePromises.push(titlePromise);
            }
            
            // Sauvegarder le contenu
            const contentPromise = $.ajax({
                url: upFrontendEditor.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'up_save_frontend_content',
                    nonce: upFrontendEditor.nonce,
                    post_id: upFrontendEditor.postId,
                    content: content
                }
            });
            savePromises.push(contentPromise);
            
            // Attendre que toutes les sauvegardes soient terminées
            $.when.apply($, savePromises)
                .done(function() {
                    showNotice('Modifications sauvegardées avec succès', 'success');
                    hasChanges = false;
                    $button.removeClass('has-changes');
                    originalContent = content;
                    originalTitle = newTitle;
                })
                .fail(function(response) {
                    const message = response.responseJSON && response.responseJSON.data && response.responseJSON.data.message 
                        ? response.responseJSON.data.message 
                        : 'Erreur lors de la sauvegarde';
                    showNotice(message, 'error');
                })
                .always(function() {
                    $button.prop('disabled', false).removeClass('saving');
                    $button.find('span').removeClass('dashicons-update').addClass('dashicons-yes');
                });
        });
        
        // Bouton Annuler
        $('#up-cancel-edit').on('click', function() {
            if (hasChanges) {
                if (confirm('Voulez-vous vraiment annuler vos modifications ?')) {
                    $editableContent.html(originalContent);
                    
                    // Restaurer le titre
                    const $title = $('.up-editable-title');
                    if ($title.length && originalTitle) {
                        $title.text(originalTitle);
                    }
                    
                    // Ré-ajouter les boutons d'édition sur les images
                    addImageEditButtons();
                    
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
        
        // Ajouter un indicateur visuel sur les éléments éditables au survol (délégation)
        $(document).on('mouseenter', '.up-editable', function() {
            $(this).addClass('up-hover');
        }).on('mouseleave', '.up-editable', function() {
            $(this).removeClass('up-hover');
        });
        
        // Focus sur un élément éditable (délégation)
        $(document).on('focus', '.up-editable', function() {
            $(this).addClass('up-focused');
        }).on('blur', '.up-editable', function() {
            $(this).removeClass('up-focused');
        });
        
    });
    
})(jQuery);
