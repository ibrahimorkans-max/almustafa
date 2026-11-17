{{-- The animations of the sign-in checks (shield, progress bar, steps, success tick, "Verifying…"), once per page. --}}
@once
    <style>
        .rmv { --rmv-c: rgb(var(--primary-600)); --rmv-ok: rgb(var(--success-600)); --rmv-bad: rgb(var(--danger-600)); --rmv-track: rgba(127,127,127,.18);
            position: relative; overflow: hidden; border-radius: 1rem; padding: 1.5rem; background: linear-gradient(135deg, rgba(var(--primary-500), .10), rgba(var(--primary-500), .02) 60%);
            border: 1px solid rgba(var(--primary-500), .25); animation: rmv-in .5s ease-out both; }
        .rmv-top { display: flex; gap: 1.25rem; align-items: center; }
        .rmv-shield { position: relative; flex: 0 0 auto; width: 76px; height: 76px; display: grid; place-items: center; }
        .rmv-shield::before, .rmv-shield::after { content: ""; position: absolute; inset: 0; border-radius: 9999px; border: 2px solid var(--rmv-c); opacity: 0; animation: rmv-ring 2.4s ease-out infinite; }
        .rmv-shield::after { animation-delay: 1.2s; }
        .rmv-shield svg { width: 60px; height: 60px; position: relative; z-index: 1; filter: drop-shadow(0 6px 12px rgba(var(--primary-600), .35)); }
        .rmv-scan { position: absolute; left: 16px; right: 16px; height: 3px; border-radius: 3px; z-index: 2; background: linear-gradient(90deg, transparent, #fff, transparent); opacity: .9; animation: rmv-scan 1.8s ease-in-out infinite; }
        .rmv[data-state=done] .rmv-shield::before, .rmv[data-state=done] .rmv-shield::after, .rmv[data-state=done] .rmv-scan,
        .rmv[data-state=failed] .rmv-shield::before, .rmv[data-state=failed] .rmv-shield::after, .rmv[data-state=failed] .rmv-scan { display: none; }
        .rmv-title { font-size: 1.15rem; font-weight: 700; line-height: 1.3; }
        .rmv-sub { margin-top: .2rem; font-size: .875rem; opacity: .75; }
        .rmv-bar { margin-top: 1.25rem; height: 10px; border-radius: 9999px; background: var(--rmv-track); overflow: hidden; }
        .rmv-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--rmv-c), rgb(var(--primary-400)), var(--rmv-c)); background-size: 200% 100%;
            animation: rmv-shimmer 1.6s linear infinite; transition: width .9s cubic-bezier(.2,.8,.2,1); }
        .rmv[data-state=done] .rmv-fill { background: var(--rmv-ok); animation: none; }
        .rmv[data-state=failed] .rmv-fill { background: var(--rmv-bad); animation: none; }
        .rmv-pct { display: flex; justify-content: space-between; margin-top: .35rem; font-size: .75rem; opacity: .7; }
        .rmv-steps { margin-top: 1.1rem; display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); }
        .rmv-step { display: flex; align-items: center; gap: .55rem; font-size: .825rem; padding: .45rem .6rem; border-radius: .6rem; transition: background .3s; }
        .rmv-step[data-s=now] { background: rgba(var(--primary-500), .12); font-weight: 600; }
        .rmv-dot { flex: 0 0 auto; width: 1.6rem; height: 1.6rem; border-radius: 9999px; display: grid; place-items: center; font-size: .75rem; font-weight: 700;
            background: var(--rmv-track); position: relative; }
        .rmv-step[data-s=done] .rmv-dot { background: var(--rmv-ok); color: #fff; animation: rmv-pop .45s cubic-bezier(.3,1.6,.5,1) both; }
        .rmv-step[data-s=now] .rmv-dot { background: var(--rmv-c); color: #fff; }
        .rmv-step[data-s=now] .rmv-dot::after { content: ""; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid var(--rmv-c); animation: rmv-ring 1.6s ease-out infinite; }
        .rmv-check path { stroke-dasharray: 48; stroke-dashoffset: 48; animation: rmv-draw .7s .15s ease-out forwards; }
        .rmv-check circle { stroke-dasharray: 160; stroke-dashoffset: 160; animation: rmv-draw .6s ease-out forwards; }
        .rmv-enter { animation: rmv-in .45s ease-out both; }
        .rmv-busy { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center; align-items: center; justify-content: center; background: rgba(15, 23, 42, .45); backdrop-filter: blur(3px); }
        .rmv-busy-card { display: flex; flex-direction: column; align-items: center; gap: .9rem; padding: 1.6rem 2.2rem; border-radius: 1rem; background: rgb(var(--gray-50)); color: rgb(var(--gray-900)); box-shadow: 0 20px 50px rgba(0,0,0,.25); animation: rmv-in .25s ease-out both; }
        .dark .rmv-busy-card { background: rgb(var(--gray-900)); color: rgb(var(--gray-50)); }
        .rmv-spin { width: 54px; height: 54px; border-radius: 9999px; border: 5px solid var(--rmv-track, rgba(127,127,127,.2)); border-top-color: rgb(var(--primary-600)); animation: rmv-rot .8s linear infinite; }
        .rmv-dots span { display: inline-block; animation: rmv-blink 1.2s infinite; }
        .rmv-dots span:nth-child(2) { animation-delay: .2s; } .rmv-dots span:nth-child(3) { animation-delay: .4s; }
        @keyframes rmv-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        @keyframes rmv-ring { 0% { transform: scale(.75); opacity: .7; } 100% { transform: scale(1.35); opacity: 0; } }
        @keyframes rmv-scan { 0%, 100% { top: 18px; } 50% { top: 56px; } }
        @keyframes rmv-shimmer { to { background-position: -200% 0; } }
        @keyframes rmv-pop { 0% { transform: scale(.4); } 100% { transform: scale(1); } }
        @keyframes rmv-draw { to { stroke-dashoffset: 0; } }
        @keyframes rmv-rot { to { transform: rotate(360deg); } }
        @keyframes rmv-blink { 0%, 80%, 100% { opacity: .2; } 40% { opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .rmv *, .rmv, .rmv-busy-card, .rmv-spin { animation: none !important; transition: none !important; } .rmv-check path, .rmv-check circle { stroke-dashoffset: 0; } }
    </style>
@endonce
