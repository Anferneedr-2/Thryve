import '../css/app.css';
import { createRoot } from 'react-dom/client';
import { AuthProvider, useAuth } from './lib/auth';
import { RouterProvider, useRouter } from './lib/router';
import { BrandingProvider } from './lib/branding';
import { ToastProvider } from './components/ui/Feedback';
import { Skeleton } from './components/ui/Feedback';
import { Card } from './components/ui/Layout';
import { Button } from './components/ui/Button';
import AppLayout from './components/AppLayout';
import Account from './pages/Account';
import Attendance from './pages/Attendance';
import TeamAttendance from './pages/TeamAttendance';
import ComponentGallery from './pages/ComponentGallery';
import Departments from './pages/Departments';
import Home from './pages/Home';
import Settings from './pages/Settings';
import System from './pages/System';
import InstallWizard from './pages/InstallWizard';
import Login from './pages/Login';
import People from './pages/People';
import PolicyAck from './pages/PolicyAck';
import TokenGallery from './pages/TokenGallery';

const ADMIN_ONLY = new Set(['/people', '/departments', '/team-attendance', '/settings', '/system']);

function Screen() {
    const { path, navigate } = useRouter();
    const { user } = useAuth();
    const isAdmin = user.role === 'admin' || user.role === 'super_admin';

    if (path === '/people' && isAdmin) return <People />;
    if (path === '/departments' && isAdmin) return <Departments />;
    if (path === '/team-attendance' && isAdmin) return <TeamAttendance />;
    if (path === '/settings' && user.role === 'super_admin') return <Settings />;
    if (path === '/system' && user.role === 'super_admin') return <System />;
    if (path === '/attendance') return <Attendance />;
    if (path === '/account') return <Account />;
    if (path === '/') return <Home />;
    return (
        <Card title={ADMIN_ONLY.has(path) ? 'You do not have access to this page' : 'Page not found'}>
            <p>{ADMIN_ONLY.has(path) ? 'Ask a Super Admin if you need it.' : 'The page you asked for does not exist.'}</p>
            <div><Button variant="soft" size="sm" onClick={() => navigate('/')}>Go to Home</Button></div>
        </Card>
    );
}

function Gate() {
    const { status, user } = useAuth();
    const { path } = useRouter();

    // Temporary developer galleries; remove before launch.
    if (path === '/design/components') return <ComponentGallery />;
    if (path === '/design/tokens') return <TokenGallery />;

    if (status === 'loading') return <main className="mx-auto max-w-[440px] px-6 py-20"><Skeleton lines={4} /></main>;
    if (status === 'guest') return <Login />;
    if (!user.policy_acknowledged) return <PolicyAck />;
    return <AppLayout><Screen /></AppLayout>;
}

function App() {
    // Not installed: the wizard is the only screen (the server also blocks the rest of the API).
    if (!window.__APP__?.installed) return <InstallWizard />;
    return (
        <RouterProvider>
            <BrandingProvider>
                <ToastProvider>
                    <AuthProvider><Gate /></AuthProvider>
                </ToastProvider>
            </BrandingProvider>
        </RouterProvider>
    );
}

createRoot(document.getElementById('root')).render(<App />);
