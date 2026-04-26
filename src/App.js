import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import SyncPanel from './components/SyncPanel';
import PresetsSection from './components/PresetsSection';

export default function App() {
	const [ status, setStatus ] = useState( null );
	const [ presets, setPresets ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const fetchAll = useCallback( async () => {
		try {
			const [ statusData, presetsData ] = await Promise.all( [
				apiFetch( { path: '/tp/v1/status' } ),
				apiFetch( { path: '/tp/v1/presets' } ),
			] );
			setStatus( statusData );
			setPresets( presetsData );
		} catch ( e ) {
			setError( e.message || 'Failed to load data.' );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		fetchAll();
	}, [ fetchAll ] );

	if ( loading ) {
		return (
			<div className="tp-app-loading">
				<span className="spinner is-active" style={ { float: 'none', margin: 0 } } />
			</div>
		);
	}

	if ( error ) {
		return <div className="notice notice-error"><p>{ error }</p></div>;
	}

	return (
		<div className="tp-app">
			<SyncPanel
				status={ status }
				onSyncDone={ setStatus }
			/>
			<PresetsSection
				presets={ presets }
				onChange={ setPresets }
			/>
		</div>
	);
}
