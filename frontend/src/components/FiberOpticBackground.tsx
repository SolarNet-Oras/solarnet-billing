export default function FiberOpticBackground(): React.JSX.Element {
  return <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden bg-[#03151d]">
    <img
      src="/solarnet-auth-background.png"
      alt=""
      className="absolute inset-0 h-full w-full object-cover object-center"
      draggable={false}
    />
    <div className="absolute inset-0 bg-slate-950/45" />
    <div className="absolute inset-0 bg-[radial-gradient(circle_at_center,transparent_18%,rgba(2,8,23,.25)_65%,rgba(2,8,23,.7)_100%)]" />
  </div>;
}
