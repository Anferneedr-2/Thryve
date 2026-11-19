import { useAuth } from '../lib/auth';
import { useRouter } from '../lib/router';
import { Card } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { Pill } from '../components/ui/Feedback';
import { ROLE_LABEL } from '../components/AppLayout';

export default function Home() {
    const { user } = useAuth();
    const { navigate } = useRouter();
    const isAdmin = user.role === 'admin' || user.role === 'super_admin';
    return (
        <>
            <div className="space-y-2">
                <h1 className="text-4xl">Hello, {user.name}</h1>
                <div className="flex flex-wrap items-center gap-2">
                    <Pill tone="info">{ROLE_LABEL[user.role]}</Pill>
                    {user.departments.map((d) => <Pill key={d.id}>{d.name}</Pill>)}
                </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Card title="AI assistant" icon="chat">
                    <p>Get help with writing, summarizing and planning your everyday work.</p>
                    <div><Button size="sm" variant="primary" onClick={() => navigate('/chat')}>Open the assistant</Button></div>
                </Card>
                {isAdmin && (
                    <Card title="People" icon="users">
                        <p>Add employees, assign departments and manage accounts.</p>
                        <div><Button size="sm" variant="soft" onClick={() => navigate('/people')}>Manage people</Button></div>
                    </Card>
                )}
            </div>
        </>
    );
}
