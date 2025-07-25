const Vue = require("vue");

// Add new Vue Components to components object
// key: Vue Component Name
// value: Require ResourceModule or .Vue component
// ex (ResourceModule): VueButton: require('skins.giantbomb.vuebutton')
// ex (.Vue Component): VueButton: require('./VueButton.vue')
// note: Only required for components being mounted to a php template via the 'data-vue-component' attribute.
const components = {
  VueExampleComponent: require("skins.giantbomb.vueexamplecomponent"),
  VueSingleFileComponentExample: require("./VueSingleFileComponentExample.vue"),
};

Object.entries(components).forEach(([name, component]) => {
  const mounts = document.querySelectorAll(`[data-vue-component="${name}"]`);
  mounts.forEach((el) => {
    const props = {};
    for (const attr of el.attributes) {
      if (attr.name.startsWith("data-") && attr.name !== "data-vue-component") {
        const propName = attr.name.slice(5);
        props[propName] = attr.value;
      }
    }
    Vue.createMwApp(component, props).mount(el);
  });
});
