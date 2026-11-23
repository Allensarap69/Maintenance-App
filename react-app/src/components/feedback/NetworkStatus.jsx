import { useEffect, useRef, useState } from 'react';
import { WifiOff } from 'lucide-react';
import { useToast } from './ToastProvider';

// Sticky offline banner + "back online" toast.
export default function NetworkStatus() {
  const [online, setOnline] = useState(() => navigator.onLine !== false);
  const toast = useToast();
  const wasOffline = useRef(false);

  useEffect(() => {
    const goOnline = () => {
      setOnline(true);
      if (wasOffline.current) toast.success('You’re back online', { message: 'All features are available again.' });
      wasOffline.current = false;
    };
    const goOffline = () => {
      setOnline(false);
      wasOffline.current = true;
    };
    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);
    return () => {
      window.removeEventListener('online', goOnline);
      window.removeEventListener('offline', goOffline);
    };
  }, [toast]);

  if (online) return null;
  return (
    <div className="ux-offline-banner" role="alert">
      <WifiOff /> You’re offline. Sign-in and registration will work again once you reconnect.
    </div>
  );
}
