import {createInitializer} from './initialize.js';

const initialize = createInitializer();
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initialize(document), {once:true});
else initialize(document);
document.addEventListener('joomla:updated', event => initialize(event.target));
