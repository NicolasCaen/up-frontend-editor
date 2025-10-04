/**
 * UP Frontend Editor - JavaScript
 * Gère l'édition en front-end avec contenteditable
 */

(function($) {
    'use strict';
    
    let hasChanges = false;
    let originalContent = '';
    let baseSerialized = '';
    
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
        
        // Charger le HTML éditable via REST pour garantir data-upfe-* et contenteditable
        const $editableContent = $('.up-editable-content');
        // Diagnostics
        try { console.log('[UPFE] canEdit:', upFrontendEditor?.canEdit, 'enabled:', upFrontendEditor?.enabled, 'postId:', upFrontendEditor?.postId); } catch(e){}
        // Fallback restUrl if absent
        if (!upFrontendEditor.restUrl) {
            try { upFrontendEditor.restUrl = new URL('/wp-json/up-fe/v1/', window.location.origin).toString(); } catch(e){}
        }
        try { console.log('[UPFE] restUrl:', upFrontendEditor?.restUrl); } catch(e){}

        if ($editableContent.length && upFrontendEditor.restUrl) {
            console.log('[UPFE] Fetch blocks via REST…');
            fetch(upFrontendEditor.restUrl + 'blocks/' + upFrontendEditor.postId, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': upFrontendEditor.restNonce }
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.rendered_html) {
                    $editableContent.html(data.rendered_html);
                }
                if (data && data.serialized) {
                    baseSerialized = data.serialized;
                }
                // Sauvegarder le contenu original après chargement
                originalContent = $editableContent.html();
                
                // Ajouter les boutons d'édition d'images
                setTimeout(addImageEditButtons, 50);
            })
            .catch((err) => {
                console.error('[UPFE] REST GET blocks failed:', err);
                // Fallback: garder le HTML courant
                originalContent = $editableContent.html();
            });
        } else if ($editableContent.length) {
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
                    
                    // Chercher l'élément avec classe wp-block-cover__image-background (img ou div)
                    let $bgElement = $cover.find('.wp-block-cover__image-background');
                    
                    if ($bgElement.length > 0) {
                        // Vérifier si c'est une img ou un div
                        if ($bgElement.is('img')) {
                            // C'est une image: mettre à jour le src
                            $bgElement.attr('src', attachment.url);
                            $bgElement.attr('data-attachment-id', attachment.id || '');
                            
                            // Ajouter la classe wp-image-X si on a un ID
                            if (attachment.id) {
                                $bgElement.removeClass(function(index, className) {
                                    return (className.match(/(^|\s)wp-image-\S+/g) || []).join(' ');
                                });
                                $bgElement.addClass('wp-image-' + attachment.id);
                            }
                            
                            // IMPORTANT: Préserver les attributs data-upfe-* s'ils existent déjà
                            // (ils sont injectés par le PHP lors du rendu initial)
                        } else {
                            // C'est un div: mettre à jour le background-image
                            $bgElement.css('background-image', 'url(' + attachment.url + ')');
                            $bgElement.attr('data-attachment-id', attachment.id || '');
                            
                            // Ajouter la classe wp-image-X si on a un ID
                            if (attachment.id) {
                                $bgElement.removeClass(function(index, className) {
                                    return (className.match(/(^|\s)wp-image-\S+/g) || []).join(' ');
                                });
                                $bgElement.addClass('wp-image-' + attachment.id);
                            }
                            
                            // IMPORTANT: Préserver les attributs data-upfe-* s'ils existent déjà
                        }
                    } else {
                        // Pas d'élément trouvé: créer une img (sans data-upfe car nouveau)
                        let $coverImg = $('<img class="wp-block-cover__image-background wp-image-' + attachment.id + '" src="' + attachment.url + '" data-object-fit="cover" />');
                        $coverImg.attr('data-attachment-id', attachment.id || '');
                        $cover.prepend($coverImg);
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
            const $editableContent = $('.up-editable-content');
            const content = $editableContent.length ? $editableContent.html() : '';

            // Construire updates_map à partir des marqueurs data-upfe-*
            function buildUpdatesMap(rootEl) {
                const updates = {};
                if (!rootEl) return updates;
                const nodes = rootEl.querySelectorAll('[data-upfe-block][data-upfe-key][data-upfe-field]');
                nodes.forEach((el) => {
                    const uid = el.getAttribute('data-upfe-block');
                    const key = el.getAttribute('data-upfe-key');
                    const field = el.getAttribute('data-upfe-field');
                    if (!uid || !key || !field) return;
                    if (!updates[uid]) updates[uid] = {};
                    if (field === 'text') {
                        updates[uid][key] = { type: 'text', content: el.innerHTML };
                    } else if (field === 'link') {
                        updates[uid][key] = { type: 'link', content: el.innerHTML, href: el.getAttribute('href') || '' };
                    } else if (field === 'image') {
                        updates[uid][key] = {
                            type: 'image',
                            url: el.getAttribute('src') || '',
                            srcset: el.getAttribute('srcset') || '',
                            sizes: el.getAttribute('sizes') || '',
                            width: el.getAttribute('width') || '',
                            height: el.getAttribute('height') || '',
                            alt: el.getAttribute('alt') || '',
                            id: (el.getAttribute('data-attachment-id') || '').replace(/[^0-9]/g, '')
                        };
                    } else if (field === 'cover') {
                        // cover: peut être un div avec background-image OU un img
                        let url = '';
                        let id = '';
                        if (el.tagName.toLowerCase() === 'img') {
                            url = el.getAttribute('src') || '';
                            id = (el.getAttribute('data-attachment-id') || '').replace(/[^0-9]/g, '');
                        } else {
                            const style = el.getAttribute('style') || '';
                            const m = style.match(/background-image:\s*url\(("|')?(.*?)\1\)/i);
                            if (m && m[2]) url = m[2];
                            id = (el.getAttribute('data-attachment-id') || '').replace(/[^0-9]/g, '');
                        }
                        updates[uid][key] = { type: 'cover', url: url, id: id };
                    }
                });
                try { console.log('[UPFE] updates_map blocks:', Object.keys(updates).length); } catch(e){}
                return updates;
            }
            const updatesMap = buildUpdatesMap($editableContent.get(0));
            
            let savePromises = [];

            // Récupérer le titre courant
            const $editableTitleNow = $('.up-editable-title');
            const newTitle = $editableTitleNow.length ? $editableTitleNow.text() : '';

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
            
            // Sauvegarder le contenu via REST (fallback séquentiel ou updates si data-upfe-*)
            const restPromise = $.ajax({
                url: upFrontendEditor.restUrl + 'save/' + upFrontendEditor.postId,
                method: 'POST',
                data: JSON.stringify({ content_html: content, updates: updatesMap, base_serialized: baseSerialized }),
                contentType: 'application/json; charset=UTF-8',
                processData: false,
                beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', upFrontendEditor.restNonce); }
            }).done(function(){
                if (window.console) console.log('[UPFE] REST save success');
            }).fail(function(xhr){
                if (window.console) console.error('[UPFE] REST save error', xhr);
            });
            savePromises.push(restPromise);
            
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
        
        // Bouton Créer un nouveau post
        $('#up-create-post').on('click', function() {
            const $button = $(this);
            
            if (!confirm('Créer un nouveau post basé sur le contenu actuel ?')) {
                return;
            }
            
            // Désactiver le bouton pendant la création
            $button.prop('disabled', true).addClass('saving');
            $button.find('span').removeClass('dashicons-plus').addClass('dashicons-update');
            
            // Récupérer le contenu actuel et le titre
            const $editableContent = $('.up-editable-content');
            const content = $editableContent.length ? $editableContent.html() : '';
            const $editableTitle = $('.up-editable-title');
            const title = $editableTitle.length ? $editableTitle.text() : '';
            
            // Construire updates_map à partir des marqueurs data-upfe-*
            function buildUpdatesMap(rootEl) {
                const updates = {};
                if (!rootEl) return updates;
                const nodes = rootEl.querySelectorAll('[data-upfe-block][data-upfe-key][data-upfe-field]');
                nodes.forEach((el) => {
                    const uid = el.getAttribute('data-upfe-block');
                    const key = el.getAttribute('data-upfe-key');
                    const field = el.getAttribute('data-upfe-field');
                    if (!uid || !key || !field) return;
                    if (!updates[uid]) updates[uid] = {};
                    if (field === 'text') {
                        updates[uid][key] = { type: 'text', content: el.innerHTML };
                    } else if (field === 'link') {
                        updates[uid][key] = { type: 'link', content: el.innerHTML, href: el.getAttribute('href') || '' };
                    } else if (field === 'image') {
                        updates[uid][key] = {
                            type: 'image',
                            url: el.getAttribute('src') || '',
                            srcset: el.getAttribute('srcset') || '',
                            sizes: el.getAttribute('sizes') || '',
                            width: el.getAttribute('width') || '',
                            height: el.getAttribute('height') || '',
                            alt: el.getAttribute('alt') || '',
                            id: (el.getAttribute('data-attachment-id') || '').replace(/[^0-9]/g, '')
                        };
                    } else if (field === 'cover') {
                        let url = '';
                        let id = '';
                        if (el.tagName.toLowerCase() === 'img') {
                            url = el.getAttribute('src') || '';
                            id = (el.getAttribute('data-attachment-id') || '').replace(/[^0-9]/g, '');
                        } else {
                            const style = el.getAttribute('style') || '';
                            const m = style.match(/background-image:\s*url\(("|')?(.*?)\1\)/i);
                            if (m && m[2]) url = m[2];
                            id = (el.getAttribute('data-attachment-id') || '').replace(/[^0-9]/g, '');
                        }
                        updates[uid][key] = { type: 'cover', url: url, id: id };
                    }
                });
                return updates;
            }
            const updatesMap = buildUpdatesMap($editableContent.get(0));
            
            // Créer le post via AJAX avec le contenu
            $.ajax({
                url: upFrontendEditor.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'up_create_new_post',
                    nonce: upFrontendEditor.nonce,
                    post_type: upFrontendEditor.postType || 'post',
                    title: title || '',
                    content_html: content,
                    updates: JSON.stringify(updatesMap),
                    base_serialized: baseSerialized
                }
            })
            .done(function(response) {
                if (response.success && response.data.post_id) {
                    showNotice('Post créé avec succès. Redirection...', 'success');
                    // Rediriger vers le nouveau post après 1 seconde
                    setTimeout(function() {
                        window.location.href = response.data.permalink;
                    }, 1000);
                } else {
                    showNotice(response.data.message || 'Erreur lors de la création', 'error');
                    $button.prop('disabled', false).removeClass('saving');
                    $button.find('span').removeClass('dashicons-update').addClass('dashicons-plus');
                }
            })
            .fail(function(xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                    ? xhr.responseJSON.data.message 
                    : 'Erreur lors de la création du post';
                showNotice(message, 'error');
                $button.prop('disabled', false).removeClass('saving');
                $button.find('span').removeClass('dashicons-update').addClass('dashicons-plus');
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
