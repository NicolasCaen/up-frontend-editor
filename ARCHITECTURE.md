# UP Frontend Editor - Architecture Modulaire

## 📁 Structure du Projet

```
up-frontend-editor/
├── up-frontend-editor.php          # Fichier principal du plugin
├── assets/
│   ├── js/
│   │   ├── frontend-editor-new.js  # Point d'entrée (modules ES6)
│   │   ├── frontend-editor.js      # Ancienne version (à supprimer après tests)
│   │   ├── core/
│   │   │   ├── block-handler.js    # Classe de base pour tous les blocs
│   │   │   └── editor-manager.js   # Gestionnaire principal
│   │   ├── blocks/
│   │   │   ├── cover-block.js      # Handler pour blocs Cover (bannières)
│   │   │   ├── image-block.js      # Handler pour blocs Image
│   │   │   ├── text-blocks.js      # Handler pour textes (p, h1-h6, li, etc.)
│   │   │   └── title-block.js      # Handler pour titre de page
│   │   └── utils/
│   │       ├── media-manager.js    # Gestion médiathèque WordPress
│   │       └── ajax-handler.js     # Gestion des requêtes AJAX
│   └── css/
│       └── frontend-editor.css     # Styles
```

## 🏗️ Architecture

### Principes

1. **Modularité** : Chaque type de bloc a son propre handler
2. **Extensibilité** : Facile d'ajouter de nouveaux types de blocs
3. **Séparation des responsabilités** : Chaque module a un rôle précis
4. **Pas de compilation** : Modules ES6 natifs du navigateur

### Classes Principales

#### `EditorManager`
- Orchestrateur principal
- Gère l'état global (changements, sauvegarde, annulation)
- Enregistre et initialise les handlers de blocs

#### `BlockHandler` (classe de base)
- Classe abstraite pour tous les handlers
- Méthodes communes : `init()`, `addEditButtons()`, `attachEvents()`
- Chaque handler hérite de cette classe

#### Handlers de Blocs
- **`CoverBlockHandler`** : Bannières avec background-image
- **`ImageBlockHandler`** : Images normales
- **`TextBlocksHandler`** : Paragraphes, titres, listes
- **`TitleBlockHandler`** : Titre de la page

#### Utilitaires
- **`MediaManager`** : Ouvre la médiathèque, gère les IDs d'attachement
- **`AjaxHandler`** : Envoie les requêtes AJAX au serveur

## 🚀 Comment Ajouter un Nouveau Type de Bloc

### 1. Créer le Handler

```javascript
// assets/js/blocks/mon-nouveau-bloc.js
import { BlockHandler } from '../core/block-handler.js';

export class MonNouveauBlocHandler extends BlockHandler {
    constructor() {
        super({
            blockSelector: '.mon-bloc',
            blockType: 'mon-bloc'
        });
    }

    addEditButtons() {
        // Ajouter les boutons d'édition
    }

    attachEvents() {
        // Attacher les événements
    }
}
```

### 2. Enregistrer le Handler

```javascript
// Dans frontend-editor-new.js
import { MonNouveauBlocHandler } from './blocks/mon-nouveau-bloc.js';

// ...
editor.registerBlockHandler(new MonNouveauBlocHandler());
```

C'est tout ! Le handler sera automatiquement initialisé.

## 🔧 Configuration

### Activer/Désactiver le Mode Debug

Dans `frontend-editor-new.js` :
```javascript
const editor = new EditorManager({
    // ...
    debug: true // false en production
});
```

### Passer de l'Ancienne à la Nouvelle Architecture

Le plugin charge actuellement `frontend-editor-new.js`. Pour revenir à l'ancienne version :

Dans `up-frontend-editor.php`, ligne 90 :
```php
// Ancienne version
plugin_dir_url(__FILE__) . 'assets/js/frontend-editor.js',

// Nouvelle version (actuelle)
plugin_dir_url(__FILE__) . 'assets/js/frontend-editor-new.js',
```

## 📝 Avantages de cette Architecture

✅ **Maintenabilité** : Code organisé, facile à comprendre
✅ **Extensibilité** : Ajouter des blocs sans toucher au code existant
✅ **Testabilité** : Chaque module peut être testé indépendamment
✅ **Performance** : Modules chargés de manière optimale
✅ **Pas de build** : Développement direct, pas de compilation
✅ **Moderne** : Utilise les standards ES6 natifs

## 🐛 Débogage

Ouvrir la console (F12) pour voir les logs :
- `[EditorManager]` : Logs du gestionnaire principal
- `[cover]`, `[image]`, `[text]`, `[title]` : Logs des handlers

## 📦 Prochaines Étapes

- [ ] Créer la structure PHP modulaire (classes par bloc)
- [ ] Ajouter des tests unitaires
- [ ] Documenter l'API PHP
- [ ] Créer un système de hooks pour étendre le plugin
