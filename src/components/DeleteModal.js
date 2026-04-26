import { useState } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

export default function DeleteModal( { slug, name, onClose, onDeleted } ) {
	const [ deleting, setDeleting ] = useState( false );
	const [ error, setError ] = useState( null );

	const handleDelete = async () => {
		setDeleting( true );
		try {
			await apiFetch( { path: `/tp/v1/presets/${ slug }`, method: 'DELETE' } );
			onDeleted( slug );
		} catch ( e ) {
			setError( e.message || 'Failed to delete preset.' );
			setDeleting( false );
		}
	};

	return (
		<Modal title="Delete Preset" onRequestClose={ onClose } className="tp-delete-modal">
			{ error && (
				<div className="notice notice-error inline" style={ { margin: '0 0 16px' } }>
					<p>{ error }</p>
				</div>
			) }

			<p>
				Delete <strong>{ name || slug }</strong>? Any shortcodes using{ ' ' }
				<code>{ `[tp_reviews id="${ slug }"]` }</code> will stop working.
			</p>

			<div className="tp-modal-actions">
				<Button variant="tertiary" onClick={ onClose }>
					Cancel
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={ deleting }
					disabled={ deleting }
					onClick={ handleDelete }
				>
					Delete Preset
				</Button>
			</div>
		</Modal>
	);
}
