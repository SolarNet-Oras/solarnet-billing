import { lazy, Suspense } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from '@/context/AuthContext';
import { ThemeProvider } from '@/context/ThemeContext';

const AttendanceAppPage = lazy(() => import('@/pages/AttendanceAppPage'));
const AttendanceInstallPage = lazy(() => import('@/pages/AttendanceInstallPage'));
const LoginPage = lazy(() => import('@/pages/LoginPage'));

const loading = <div className="grid min-h-screen place-items-center bg-slate-950 p-6 text-center text-sky-100"><div><div className="mx-auto h-9 w-9 animate-spin rounded-full border-4 border-sky-300/25 border-t-sky-300"/><p className="mt-4 text-sm font-semibold">Opening SolarNet Attendance…</p></div></div>;

export default function AttendanceRoot(): React.JSX.Element {
  return <ThemeProvider><BrowserRouter><AuthProvider><Suspense fallback={loading}><Routes>
    <Route path="/attendance-app/install" element={<AttendanceInstallPage/>}/>
    <Route path="/attendance-app/login" element={<LoginPage/>}/>
    <Route path="/attendance-app" element={<AttendanceAppPage/>}/>
    <Route path="/attendance-app/" element={<AttendanceAppPage/>}/>
    <Route path="*" element={<Navigate to="/attendance-app" replace/>}/>
  </Routes></Suspense></AuthProvider></BrowserRouter></ThemeProvider>;
}
