import { render } from '@wordpress/element';
import App from './App';
import './style.css';

const root = document.getElementById( 'tp-admin-root' );
if ( root ) {
	render( <App />, root );
}
