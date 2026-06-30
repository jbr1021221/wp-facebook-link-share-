(function (wp) {
    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editPost;
    const { CheckboxControl } = wp.components;
    const { withSelect, withDispatch } = wp.data;
    const { compose } = wp.compose;

    const FBSharePanel = compose(
        withSelect((select) => ({
            metaValue: select('core/editor').getEditedPostAttribute('meta')['_fb_auto_share_enabled'],
        })),
        withDispatch((dispatch) => ({
            setMetaValue(value) {
                dispatch('core/editor').editPost({ meta: { _fb_auto_share_enabled: value } });
            },
        }))
    )(({ metaValue, setMetaValue }) => (
        wp.element.createElement(
            PluginDocumentSettingPanel,
            { name: 'fb-share-panel', title: 'Facebook Sharing', icon: 'facebook-alt' },
            wp.element.createElement(CheckboxControl, {
                label: 'Share this post to Facebook when published',
                checked: !!metaValue,
                onChange: setMetaValue,
            }),
            wp.element.createElement('p', { className: 'description' },
                'Note: Defaults to not sharing. You must explicitly check this box per post.')
        )
    ));

    registerPlugin('fb-share-panel', { render: FBSharePanel });
})(window.wp);
