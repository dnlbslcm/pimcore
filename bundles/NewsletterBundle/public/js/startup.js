pimcore.registerNS("pimcore.bundle.newsletter.startup");

pimcore.bundle.newsletter.startup = Class.create({
    type: 'newsletter',

    initialize: function () {
        document.addEventListener(pimcore.events.prepareDocumentTreeContextMenu, this.onPrepareDocumentTreeContextMenu.bind(this));
    },

    onPrepareDocumentTreeContextMenu: function (e) {
        var document = e.detail.document;
        var menu = e.detail.menu;
        var tree = e.detail.tree;
        var me = this;

        if (tree.tree.getSelectionModel().getSelected().length > 1) {
            return;
        }

        var documentMenu = {
            newsletter: [],
        };

        if (tree.tree.getSelectionModel().getSelected().length > 1) {
            return;
        }

        var childSupportedDocument = (document.data.type == "page" || document.data.type == "folder"
            || document.data.type == "link" || document.data.type == "hardlink"
            || document.data.type == "printcontainer" || document.data.type == "headlessdocument");


        // do not add the newsletter under print containers
        if(childSupportedDocument && document.data.permissions.create && !pimcore.helpers.documentTypeHasSpecificRole(document.data.type, "only_printable_childrens")) {
            documentMenu = this.populatePredefinedDocumentTypes(documentMenu, tree, document);
            // empty newsletter
            documentMenu.newsletter.push({
                text: "&gt; " + t("blank"),
                iconCls: "pimcore_icon_newsletter pimcore_icon_overlay_add",
                handler: me.addDocument.bind(tree, tree, document, "newsletter")
            });

            // add after email, should be 5

            menu.insert(5, new Ext.menu.Item({
                text: t('add_newsletter'),
                iconCls: "pimcore_icon_newsletter pimcore_icon_overlay_add",
                menu: documentMenu.newsletter,
                hideOnClick: false
            }));
        }
    },



    addDocument : function (tree, record, type, docTypeId) {
        var textKeyTitle = t("add_" + type);
        var textKeyMessage = t("enter_the_name_of_the_new_item");

        Ext.MessageBox.prompt(textKeyTitle, textKeyMessage, function (tree, record, type, docTypeId, button, value) {
            if (button == "ok") {
                if (value) {
                    // check for ident filename in current level
                    if (pimcore.elementservice.isKeyExistingInLevel(record, value)) {
                        return;
                    }

                    if(pimcore.elementservice.isDisallowedDocumentKey(record.id, value)) {
                        return;
                    }

                    let params = {
                        key: pimcore.helpers.getValidFilename(value, "document"),
                        type: type,
                        docTypeId: docTypeId,
                        sourceTree: tree,
                        elementType: "document",
                        index: record.childNodes.length,
                        parentId: record.id,
                        url: Routing.generate('pimcore_admin_document_document_add')
                    };

                    pimcore.elementservice.addDocument(params);
                }
            }
        }.bind(this, tree, record, type, docTypeId));
    },

    populatePredefinedDocumentTypes: function(documentMenu, tree, record) {
        var me = this;
        var document_types = pimcore.globalmanager.get("document_types_store");
        document_types.sort([
            {property: 'priority', direction: 'ASC'},
            {property: 'translatedGroup', direction: 'ASC'},
            {property: 'translatedName', direction: 'ASC'}
        ]);
        document_types.each(function (documentMenu, typeRecord) {
            var text = Ext.util.Format.htmlEncode(typeRecord.get("translatedName"));

            if (typeRecord.get("type") === this.type) {
                documentMenu['newsletter'].push(
                    {
                        text: text,
                        iconCls: "pimcore_icon_newsletter pimcore_icon_overlay_add",
                        handler: me.addDocument.bind(this, tree, record, "newsletter", typeRecord.get("id"))
                    }
                );

            }

        }.bind(this, documentMenu), documentMenu);

        return documentMenu;
    },
});



const pimcoreBundleNewsletter = new pimcore.bundle.newsletter.startup();