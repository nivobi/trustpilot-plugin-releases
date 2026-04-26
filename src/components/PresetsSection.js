import { useState, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import PresetModal from './PresetModal';
import DeleteModal from './DeleteModal';
import PreviewModal from './PreviewModal';

function CopyButton( { text } ) {
	const [ copied, setCopied ] = useState( false );

	const handleCopy = () => {
		navigator.clipboard.writeText( text ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 1500 );
		} );
	};

	return (
		<button
			type="button"
			className={ `tp-copy-btn${ copied ? ' tp-copy-btn--done' : '' }` }
			onClick={ handleCopy }
			title="Copy shortcode"
		>
			{ copied ? '✓ Copied' : 'Copy' }
		</button>
	);
}

function Stars( { min } ) {
	return (
		<span className="tp-stars" aria-label={ `${ min } stars minimum` }>
			{ [ 1, 2, 3, 4, 5 ].map( ( i ) => (
				<span key={ i } className={ i <= min ? 'tp-star tp-star--filled' : 'tp-star' }>
					★
				</span>
			) ) }
		</span>
	);
}

function CountBadge( { count } ) {
	if ( count === null ) return <span className="tp-count-badge tp-count-badge--loading">…</span>;
	return (
		<span className="tp-count-badge">
			{ count.toLocaleString() } { count === 1 ? 'review' : 'reviews' }
		</span>
	);
}

export default function PresetsSection( { presets, onChange } ) {
	const [ editPreset, setEditPreset ] = useState( null );
	const [ showAdd, setShowAdd ] = useState( false );
	const [ deleteTarget, setDeleteTarget ] = useState( null );
	const [ previewPreset, setPreviewPreset ] = useState( null );
	const [ counts, setCounts ] = useState( {} ); // { [slug]: number | null }

	// Fetch match counts for all presets whenever the list changes.
	useEffect( () => {
		if ( presets.length === 0 ) return;

		// Seed with null (loading state) for any new slugs.
		setCounts( ( prev ) => {
			const next = { ...prev };
			presets.forEach( ( p ) => {
				if ( ! ( p.slug in next ) ) next[ p.slug ] = null;
			} );
			return next;
		} );

		presets.forEach( ( preset ) => {
			apiFetch( { path: `/tp/v1/presets/${ preset.slug }/preview?count_only=1` } )
				.then( ( data ) => {
					setCounts( ( prev ) => ( { ...prev, [ preset.slug ]: data.count } ) );
				} )
				.catch( () => {
					setCounts( ( prev ) => ( { ...prev, [ preset.slug ]: '?' } ) );
				} );
		} );
	}, [ presets ] );

	const closeModals = () => {
		setShowAdd( false );
		setEditPreset( null );
	};

	const handleSaved = ( preset, isEdit ) => {
		if ( isEdit ) {
			onChange( presets.map( ( p ) => ( p.slug === preset.slug ? preset : p ) ) );
		} else {
			onChange( [ ...presets, preset ] );
		}
		// Reset count for updated preset so it refetches.
		setCounts( ( prev ) => ( { ...prev, [ preset.slug ]: null } ) );
	};

	const handleDeleted = ( slug ) => {
		onChange( presets.filter( ( p ) => p.slug !== slug ) );
		setCounts( ( prev ) => {
			const next = { ...prev };
			delete next[ slug ];
			return next;
		} );
		setDeleteTarget( null );
	};

	return (
		<div className="tp-panel tp-panel--presets">
			<div className="tp-panel__header">
				<h2 className="tp-panel__title">Review Presets</h2>
				<Button variant="primary" onClick={ () => setShowAdd( true ) }>
					+ Add Preset
				</Button>
			</div>

			{ presets.length === 0 ? (
				<div className="tp-empty-state">
					<p>
						No presets yet — click <strong>Add Preset</strong> to create your first
						review set.
					</p>
				</div>
			) : (
				<table className="wp-list-table widefat fixed striped tp-presets-table">
					<thead>
						<tr>
							<th>Name</th>
							<th>Keywords</th>
							<th>Min Stars</th>
							<th>Matched</th>
							<th>Shortcode</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						{ presets.map( ( preset ) => (
							<tr key={ preset.slug }>
								<td>
									<strong>{ preset.name || preset.slug }</strong>
									<br />
									<code className="tp-code tp-code--muted">{ preset.slug }</code>
								</td>
								<td className="tp-keywords-cell">
									{ preset.keywords ? (
										preset.keywords
											.split( ',' )
											.map( ( kw ) => kw.trim() )
											.filter( Boolean )
											.map( ( kw, i ) => (
												<span key={ i } className="tp-keyword-chip tp-keyword-chip--sm">
													{ kw }
												</span>
											) )
									) : (
										<em className="tp-muted">All reviews</em>
									) }
								</td>
								<td>
									<Stars min={ preset.min_stars } />
								</td>
								<td className="tp-matched-cell">
									<CountBadge count={ counts[ preset.slug ] ?? null } />
									<button
										type="button"
										className="tp-row-action tp-row-action--preview"
										onClick={ () => setPreviewPreset( preset ) }
									>
										Preview →
									</button>
								</td>
								<td className="tp-shortcode-cell">
									<div className="tp-shortcode-cell-inner">
										<code className="tp-code">
											{ `[tp_reviews id="${ preset.slug }"]` }
										</code>
										<CopyButton text={ `[tp_reviews id="${ preset.slug }"]` } />
									</div>
								</td>
								<td className="tp-actions-cell">
									<button
										type="button"
										className="tp-row-action tp-row-action--edit"
										onClick={ () => setEditPreset( preset ) }
									>
										Edit
									</button>
									<button
										type="button"
										className="tp-row-action tp-row-action--delete"
										onClick={ () =>
											setDeleteTarget( {
												slug: preset.slug,
												name: preset.name || preset.slug,
											} )
										}
									>
										Delete
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ ( showAdd || editPreset ) && (
				<PresetModal
					preset={ editPreset }
					onClose={ closeModals }
					onSaved={ handleSaved }
				/>
			) }

			{ deleteTarget && (
				<DeleteModal
					slug={ deleteTarget.slug }
					name={ deleteTarget.name }
					onClose={ () => setDeleteTarget( null ) }
					onDeleted={ handleDeleted }
				/>
			) }

			{ previewPreset && (
				<PreviewModal
					preset={ previewPreset }
					onClose={ () => setPreviewPreset( null ) }
				/>
			) }
		</div>
	);
}
