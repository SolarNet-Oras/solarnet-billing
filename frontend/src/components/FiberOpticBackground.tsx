export default function FiberOpticBackground(): React.JSX.Element {
  return <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden bg-[#03151d]">
    <div className="absolute inset-0 bg-[radial-gradient(circle_at_78%_18%,rgba(6,182,212,.25),transparent_25%),radial-gradient(circle_at_18%_82%,rgba(37,99,235,.2),transparent_30%),linear-gradient(135deg,#03151d_0%,#073044_52%,#020b12_100%)]" />
    <svg viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" className="absolute inset-0 h-full w-full opacity-85">
      <defs>
        <linearGradient id="fiber-cyan" x1="0" y1="0" x2="1" y2="0"><stop stopColor="#38bdf8" stopOpacity="0"/><stop offset=".42" stopColor="#67e8f9"/><stop offset="1" stopColor="#2563eb" stopOpacity="0"/></linearGradient>
        <linearGradient id="fiber-violet" x1="0" y1="0" x2="1" y2="0"><stop stopColor="#22d3ee" stopOpacity="0"/><stop offset=".55" stopColor="#a78bfa"/><stop offset="1" stopColor="#f472b6" stopOpacity="0"/></linearGradient>
        <filter id="fiber-glow"><feGaussianBlur stdDeviation="7" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
      </defs>
      <g fill="none" strokeLinecap="round" filter="url(#fiber-glow)">
        <path d="M-120 760 C250 340 475 745 820 395 S1280 120 1570 300" stroke="url(#fiber-cyan)" strokeWidth="5"/>
        <path d="M-150 810 C240 390 500 790 850 430 S1280 165 1580 345" stroke="url(#fiber-violet)" strokeWidth="2.5"/>
        <path d="M-100 690 C285 285 485 675 790 345 S1240 85 1540 245" stroke="url(#fiber-cyan)" strokeWidth="1.5" opacity=".8"/>
        <path d="M-180 870 C265 470 520 830 910 500 S1320 250 1590 410" stroke="url(#fiber-cyan)" strokeWidth="1" opacity=".65"/>
      </g>
      <g fill="#a5f3fc" filter="url(#fiber-glow)"><circle cx="255" cy="493" r="3"/><circle cx="502" cy="605" r="2"/><circle cx="822" cy="393" r="3"/><circle cx="1047" cy="230" r="2.5"/><circle cx="1245" cy="170" r="2"/></g>
      <g fill="#c4b5fd" opacity=".8"><circle cx="170" cy="610" r="1.5"/><circle cx="606" cy="610" r="2"/><circle cx="930" cy="387" r="1.5"/><circle cx="1150" cy="238" r="2"/></g>
    </svg>
    <div className="absolute inset-0 bg-[linear-gradient(90deg,rgba(2,8,23,.45),transparent_42%,rgba(2,8,23,.28))]" />
  </div>;
}
