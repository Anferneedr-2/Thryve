export const ROLE_LABEL = { employee: 'Employee', admin: 'Admin', super_admin: 'Super Admin' };

export const isAdmin = (user) => user?.role === 'admin' || user?.role === 'super_admin';
export const isSuperAdmin = (user) => user?.role === 'super_admin';
