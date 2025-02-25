/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

pimcore.registerNS("pimcore.object.classes.data.reverseObjectRelation");
pimcore.object.classes.data.reverseObjectRelation = Class.create(pimcore.object.classes.data.data, {

    type: "reverseObjectRelation",
    /**
     * define where this datatype is allowed
     */
    allowIn: {
        object: true,
        objectbrick: false,
        fieldcollection: false,
        localizedfield: false,
        classificationstore : false
    },

    initialize: function (treeNode, initData) {
        this.type = "reverseObjectRelation";

        this.initData(initData);

        // overwrite default settings
        this.availableSettingsFields = ["name","title","tooltip","noteditable","invisible","style", "visibleGridView", "visibleSearch"];

        this.treeNode = treeNode;
    },

    getGroup: function () {
        return "relation";
    },

    getTypeName: function () {
        return t("reverse_object_relation");
    },

    getIconClass: function () {
        return "pimcore_icon_reverseObjectRelation";
    },

    getLayout: function ($super) {

        $super();

        this.specificPanel.removeAll();
        this.specificPanel.add([
            {
                xtype: "textfield",
                fieldLabel: t("width"),
                name: "width",
                value: this.datax.width
            },
            {
                xtype: "displayfield",
                hideLabel: true,
                value: t('width_explanation')
            },
            {
                xtype: "textfield",
                fieldLabel: t("height"),
                name: "height",
                value: this.datax.height
            },
            {
                xtype: "displayfield",
                hideLabel: true,
                value: t('height_explanation')
            }
        ]);


        if(!this.isInCustomLayoutEditor()) {

            this.specificPanel.add([
                {
                    xtype: 'textfield',
                    width: 600,
                    fieldLabel: t("path_formatter_service"),
                    name: 'pathFormatterClass',
                    value: this.datax.pathFormatterClass
                }
            ]);

            this.classCombo = new Ext.form.ComboBox({
                typeAhead: true,
                triggerAction: 'all',
                store: pimcore.globalmanager.get("object_types_store"),
                valueField: 'text',
                displayField: 'text',
                listWidth: 'auto',
                fieldLabel: t('owner_class'),
                name: 'ownerClassName',
                value: this.datax.ownerClassName,
                forceSelection: true,
                editable: true,
                listeners: {
                    change: function (field, classNamevalue, oldValue) {
                        this.datax.ownerClassName = classNamevalue;
                    }.bind(this)
                }
            });


            this.fieldComboStore = new Ext.data.Store({
                proxy: {
                    type: 'ajax',
                    url: Routing.generate('pimcore_admin_dataobject_dataobjecthelper_gridgetcolumnconfig'),
                    reader: {
                        type: 'json',
                        rootProperty: "availableFields"
                    },
                    extraParams: {
                        types: 'manyToManyObjectRelation,manyToOneRelation',
                        name: this.datax.ownerClassName
                    }
                },
                fields: ['key', 'label'],
                autoLoad: false,
                forceSelection: true
            });


            this.fieldCombo = new Ext.form.ComboBox({
                fieldLabel: t('owner_field'),
                value: this.datax.ownerFieldName,
                store: this.fieldComboStore,
                listWidth: 'auto',
                displayField: 'key',
                valueField: 'key',
                lastQuery: '',
                name: 'ownerFieldName',
                editable: false,
                listeners: {
                    focus: function () {
                        if (this.datax.ownerClassName != null) {
                            this.fieldCombo.store.load({params: {name: this.datax.ownerClassName}});
                        }
                    }.bind(this)
                }
            });


            this.specificPanel.add(this.classCombo);
            this.specificPanel.add(this.fieldCombo);

            this.specificPanel.add(new Ext.form.DisplayField({
                hideLabel: true,
                value: t('non_owner_description'),
                cls: "pimcore_extra_label_bottom"
            }));
        }

        return this.layout;
    },

    applySpecialData: function(source) {
        if (source.datax) {
            if (!this.datax) {
                this.datax =  {};
            }
            Ext.apply(this.datax,
                {
                    remoteOwner: source.datax.remoteOwner,
                    width: source.datax.width,
                    height: source.datax.height,
                    pathFormatterClass: source.datax.pathFormatterClass,
                    ownerClassName: source.datax.ownerClassName,
                    ownerFieldName: source.datax.ownerFieldName
                });
        }
    }



});

