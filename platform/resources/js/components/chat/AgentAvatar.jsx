import { hue, initial } from './chatUtil';

// A chatbot's avatar: its picture when one was added, otherwise its first letter on a coloured circle (the default).
// `agents` is the list the colour is chosen from, so an assistant keeps the same colour everywhere.
export default function AgentAvatar({ agent, agents, size = '' }) {
    if (agent.avatar) {
        return <span className={`cw-av img ${size}`} aria-hidden="true"><img src={agent.avatar} alt="" /></span>;
    }
    return <span className={`cw-av ${size} ${hue(agents, agent.id)}`} aria-hidden="true">{initial(agent.name)}</span>;
}
