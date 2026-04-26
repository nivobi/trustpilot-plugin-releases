import { useState, useEffect } from '@wordpress/element';
import { Modal, Button, TextControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import KeywordInput from './KeywordInput';

function nameToSlug( name ) {
	return name
		.toLowerCase()
		.replace( /[^a-z0-9\s-]/g, '' )
		.trim()
		.replace( /\s+/g, '-' )
		.replace( /-+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

function StarPicker( { value, onChange } ) {
	const [ hovered, setHovered ] = useState( 0 );
	const active = hovered || value;

	const labels = [ '', 'Any rating', '2+ stars', '3+ stars', '4+ stars', '5 stars only' ];

	return (
		<div className="tp-star-picker">
			{ [ 1, 2, 3, 4, 5 ].map( ( i ) => (
				<button
					key={ i }
					type="button"
					className={ `tp-star-btn${ i <= active ? ' tp-star-btn--active' : '' }` }
					onMouseEnter={ () => setHovered( i ) }
					onMouseLeave={ () => setHovered( 0 ) }
					onClick={ () => onChange( i ) }
					aria-label={ `Minimum ${ i } star${ i > 1 ? 's' : '' }` }
				>
					★
				</button>
			) ) }
			<span className="tp-star-picker__label">{ labels[ hovered || value ] }</span>
		</div>
	);
}

export default function PresetModal( { preset, onClose, onSaved } ) {
	const isEdit = !! preset;

	const [ name, setName ] = useState( preset?.name ?? preset?.slug ?? '' );
	const [ slug, setSlug ] = useState( preset?.slug ?? '' );
	const [ keywords, setKeywords ] = useState( preset?.keywords ?? '' );
	const [ minStars, setMinStars ] = useState( preset?.min_stars ?? 1 );
	const [ limit, setLimit ] = useState( String( preset?.limit ?? 10 ) );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );

	// Auto-generate slug from name in create mode.
	useEffect( () => {
		if ( ! isEdit ) {
			setSlug( nameToSlug( name ) );
		}
	}, [ name, isEdit ] );

	const handleSave = async () => {
		setSaving( true );
		setError( null );
		try {
			let result;
			if ( isEdit ) {
				result = await apiFetch( {
					path: `/tp/v1/presets/${ preset.slug }`,
					method: 'PUT',
					data: { name, keywords, min_stars: minStars, limit: parseInt( limit, 10 ) },
				} );
			} else {
				result = await apiFetch( {
					path: '/tp/v1/presets',
					method: 'POST',
					data: {
						slug,
						name,
						keywords,
						min_stars: minStars,
						limit: parseInt( limit, 10 ),
					},
				} );
			}
			onSaved( result, isEdit );
			onClose();
		} catch ( e ) {
			setError( e.message || 'Failed to save preset.' );
			setSaving( false );
		}
	};

	const canSave = name.trim() && slug && ! saving;

	return (
		<Modal
			title={ isEdit ? `Edit: ${ preset.name || preset.slug }` : 'Add Preset' }
			onRequestClose={ onClose }
			className="tp-preset-modal"
		>
			{ error && (
				<div className="notice notice-error inline" style={ { margin: '0 0 16px' } }>
					<p>{ error }</p>
				</div>
			) }

			<TextControl
				label="Name"
				value={ name }
				onChange={ setName }
				placeholder='e.g. "Homepage Reviews"'
				help={ isEdit
					? 'Display name for this preset.'
					: 'A human-readable name — the slug is auto-generated from it.'
				}
				__nextHasNoMarginBottom
			/>

			{ /* Slug preview */ }
			<div className="tp-slug-preview">
				<span className="tp-slug-preview__label">Slug</span>
				<code className="tp-slug-preview__value">
					{ slug || <em className="tp-muted">auto-generated</em> }
				</code>
				{ isEdit && (
					<span className="tp-slug-preview__note">
						Immutable — changing would break deployed shortcodes.
					</span>
				) }
			</div>

			<div className="tp-form-field">
				<span className="tp-form-label">Keywords</span>
				<KeywordInput
					value={ keywords }
					onChange={ setKeywords }
					placeholder="Type a keyword and press Enter…"
				/>
				<p className="description" style={ { marginTop: 6 } }>
					A review matches if its text contains any keyword. Leave empty for all
					reviews.
				</p>
			</div>

			<div className="tp-form-field">
				<span className="tp-form-label">Minimum Stars</span>
				<StarPicker value={ minStars } onChange={ setMinStars } />
			</div>

			<TextControl
				label="Limit"
				type="number"
				value={ limit }
				onChange={ setLimit }
				help="Max reviews to return for this preset (1–100)."
				min={ 1 }
				max={ 100 }
				__nextHasNoMarginBottom
			/>

			<div className="tp-modal-actions">
				<Button variant="tertiary" onClick={ onClose }>
					Cancel
				</Button>
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ ! canSave }
					onClick={ handleSave }
				>
					{ isEdit ? 'Update Preset' : 'Add Preset' }
				</Button>
			</div>
		</Modal>
	);
}
